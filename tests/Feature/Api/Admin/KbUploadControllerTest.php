<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Jobs\IngestDocumentJob;
use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.9 — admin drag-and-drop KB upload: stage → review → commit → progress.
 *
 * Coverage: stage (multipart + canonical flag + type/traversal rejection),
 * commit (move + dispatch reusing the Artisan pipeline), R21 idempotency,
 * partial-move-failure tolerance, R30 tenant isolation, status/cancel/remove.
 */
final class KbUploadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        Storage::fake('kb-staging');
        Storage::fake('kb');
        // Mirror axios: validation failures return 422 JSON, not a 302 redirect.
        $this->withHeaders(['Accept' => 'application/json']);
    }

    public function test_stage_uploads_files_and_creates_batch_with_items(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'sub_path' => 'runbooks',
            'files' => [
                UploadedFile::fake()->createWithContent('deploy.md', "# Deploy\n\nSteps."),
                UploadedFile::fake()->createWithContent('notes.txt', 'plain notes'),
            ],
        ])->assertStatus(201);

        $resp->assertJsonPath('batch.status', KbIngestBatch::STATUS_STAGED)
            ->assertJsonPath('batch.project_key', 'engineering')
            ->assertJsonPath('batch.sub_path', 'runbooks')
            ->assertJsonCount(2, 'items');

        $batchId = $resp->json('batch.id');
        $this->assertDatabaseHas('kb_ingest_batches', ['id' => $batchId, 'tenant_id' => 'default']);

        $items = KbIngestBatchItem::query()->where('batch_id', $batchId)->get();
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertSame(KbIngestBatchItem::STATUS_STAGED, $item->status);
            $this->assertTrue(Storage::disk('kb-staging')->exists($item->staging_path));
            $this->assertStringStartsWith('runbooks/', $item->destination_path);
        }

        $md = $items->firstWhere('source_type', 'markdown');
        $this->assertSame('runbooks/deploy.md', $md->destination_path);
        $this->assertFalse((bool) $md->is_canonical);
    }

    public function test_stage_rejects_unsupported_file_type(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'files' => [UploadedFile::fake()->createWithContent('malware.exe', 'x')],
        ])->assertStatus(422)->assertJsonValidationErrors('files.0');
    }

    public function test_stage_rejects_subpath_traversal(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'sub_path' => '../escape',
            'files' => [UploadedFile::fake()->createWithContent('a.md', '# A')],
        ])->assertStatus(422)->assertJsonValidationErrors('sub_path');
    }

    public function test_stage_flags_canonical_frontmatter_with_warning(): void
    {
        $admin = $this->makeAdmin();

        $canonical = "---\nslug: dec-upload-test\ntype: decision\nstatus: accepted\n---\n# Decision\n\nBody.";

        $resp = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'files' => [UploadedFile::fake()->createWithContent('decision.md', $canonical)],
        ])->assertStatus(201);

        $resp->assertJsonPath('items.0.is_canonical', true);
        $this->assertNotNull($resp->json('items.0.canonical_warning'));
    }

    public function test_commit_moves_files_and_dispatches_ingest_jobs(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();

        $batchId = $this->stageOne($admin, 'guide.md', "# Guide\n");

        $item = KbIngestBatchItem::query()->where('batch_id', $batchId)->firstOrFail();

        $this->actingAs($admin)->postJson("/api/admin/kb/uploads/{$batchId}/commit")
            ->assertStatus(202)
            ->assertJsonPath('batch.status', KbIngestBatch::STATUS_PROCESSING)
            ->assertJsonPath('items.0.status', KbIngestBatchItem::STATUS_QUEUED);

        // File moved staging → kb, staged copy removed.
        $this->assertTrue(Storage::disk('kb')->exists('guide.md'));
        $this->assertFalse(Storage::disk('kb-staging')->exists($item->staging_path));

        // Reused the EXACT Artisan path: one IngestDocumentJob per file, with
        // the batch-item id threaded through metadata for progress tracking.
        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) use ($item) {
            return $job->projectKey === 'engineering'
                && $job->relativePath === 'guide.md'
                && $job->disk === 'kb'
                && ($job->metadata['kb_upload_batch_item_id'] ?? null) === $item->id;
        });
    }

    public function test_commit_is_idempotent_second_attempt_409(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();
        $batchId = $this->stageOne($admin, 'a.md', '# A');

        $this->actingAs($admin)->postJson("/api/admin/kb/uploads/{$batchId}/commit")->assertStatus(202);
        $this->actingAs($admin)->postJson("/api/admin/kb/uploads/{$batchId}/commit")->assertStatus(409);

        // committed_at stamped exactly once; no double dispatch.
        $batch = KbIngestBatch::query()->findOrFail($batchId);
        $this->assertNotNull($batch->committed_at);
        Queue::assertPushed(IngestDocumentJob::class, 1);
    }

    public function test_commit_partial_move_failure_marks_only_that_item_failed(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'files' => [
                UploadedFile::fake()->createWithContent('ok.md', '# OK'),
                UploadedFile::fake()->createWithContent('gone.md', '# Gone'),
            ],
        ])->assertStatus(201);
        $batchId = $resp->json('batch.id');

        // Force a move failure on one item by deleting its staged file first.
        $gone = KbIngestBatchItem::query()->where('batch_id', $batchId)
            ->where('original_filename', 'gone.md')->firstOrFail();
        Storage::disk('kb-staging')->delete($gone->staging_path);

        $this->actingAs($admin)->postJson("/api/admin/kb/uploads/{$batchId}/commit")->assertStatus(202);

        $gone->refresh();
        $ok = KbIngestBatchItem::query()->where('batch_id', $batchId)
            ->where('original_filename', 'ok.md')->firstOrFail();

        $this->assertSame(KbIngestBatchItem::STATUS_FAILED, $gone->status);
        $this->assertNotNull($gone->error);
        $this->assertSame(KbIngestBatchItem::STATUS_QUEUED, $ok->status);

        // The failed file never dispatched a job.
        Queue::assertPushed(IngestDocumentJob::class, 1);
    }

    public function test_other_tenant_batch_is_not_reachable(): void
    {
        $admin = $this->makeAdmin();

        // Create a batch owned by tenant 'acme' (request runs under 'default').
        $tenant = app(TenantContext::class);
        $tenant->set('acme');
        $foreign = KbIngestBatch::create(['project_key' => 'x', 'status' => KbIngestBatch::STATUS_STAGED]);
        $tenant->set('default');

        $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$foreign->id}")->assertStatus(404);
        $this->actingAs($admin)->postJson("/api/admin/kb/uploads/{$foreign->id}/commit")->assertStatus(404);
    }

    public function test_status_endpoint_returns_counts_and_items(): void
    {
        $admin = $this->makeAdmin();
        $batchId = $this->stageOne($admin, 'a.md', '# A');

        $resp = $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/status")->assertOk();

        $resp->assertJsonPath('batch.id', $batchId)
            ->assertJsonStructure([
                'batch' => ['id', 'status', 'counts' => ['staged', 'moving', 'queued', 'processing', 'succeeded', 'failed']],
                'items' => [['id', 'original_filename', 'destination_path', 'status', 'is_canonical', 'knowledge_document_id']],
            ]);
        $this->assertSame(1, $resp->json('batch.counts.staged'));
    }

    public function test_remove_staged_item(): void
    {
        $admin = $this->makeAdmin();
        $batchId = $this->stageOne($admin, 'a.md', '# A');
        $item = KbIngestBatchItem::query()->where('batch_id', $batchId)->firstOrFail();

        $this->actingAs($admin)->deleteJson("/api/admin/kb/uploads/{$batchId}/items/{$item->id}")->assertStatus(204);

        $this->assertDatabaseMissing('kb_ingest_batch_items', ['id' => $item->id]);
        $this->assertFalse(Storage::disk('kb-staging')->exists($item->staging_path));
    }

    public function test_cancel_staged_batch(): void
    {
        $admin = $this->makeAdmin();
        $batchId = $this->stageOne($admin, 'a.md', '# A');

        $this->actingAs($admin)->postJson("/api/admin/kb/uploads/{$batchId}/cancel")
            ->assertOk()
            ->assertJsonPath('batch.status', KbIngestBatch::STATUS_CANCELLED);
    }

    private function stageOne(User $admin, string $filename, string $content): string
    {
        $resp = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'files' => [UploadedFile::fake()->createWithContent($filename, $content)],
        ])->assertStatus(201);

        return $resp->json('batch.id');
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}
