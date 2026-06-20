<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Ai\EmbeddingsResponse;
use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\EmbeddingCacheService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * v8.9 — KB UI upload: the REAL commit → queue → progress reconciliation.
 *
 * The controller commit tests ({@see KbUploadControllerTest}) fake the queue,
 * and the listener test calls the listener methods directly. NEITHER runs the
 * actual chain the feature depends on:
 *
 *   commit() dispatches IngestDocumentJob → the queue raises
 *   JobProcessing/JobProcessed → App\Listeners\KbUploadBatchItemProgress
 *   advances the item queued→processing→succeeded → finalizeBatchIfComplete
 *   flips the batch to completed.
 *
 * This test drives that chain end-to-end on the SYNC queue (so the lifecycle
 * events fire inline during commit, exactly as the local E2E web server does
 * and as the CI queue worker does asynchronously) with the embedding provider
 * mocked. It is the in-process guard that would have caught the CI regression
 * where the chain never reconciled (kb-upload.spec.ts hanging at data-done="0").
 *
 * Requires App\Providers\KbUploadServiceProvider (the listener wiring), which
 * is registered for the whole suite in Tests\TestCase::getEnvironmentSetUp.
 */
final class KbUploadCommitIntegrationTest extends TestCase
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
        $this->withHeaders(['Accept' => 'application/json']);

        // Run the dispatched IngestDocumentJob inline so its real queue
        // lifecycle events fire during commit() (mirrors the local E2E sync
        // queue / the CI worker that drains the database queue).
        config(['queue.default' => 'sync']);

        // Keep the test to the upload chain: silence the post-ingest follow-on
        // jobs that would otherwise also run inline under sync and reach for AI.
        config([
            'kb.change_analysis.enabled' => false,
            'kb.autowiki.enabled' => false,
        ]);

        // Mock the only external hop (embeddings) so the real parse→chunk→embed
        // →persist pipeline runs without a provider — same pattern as
        // DocumentIngestorTest.
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(fn () => [0.1, 0.2, 0.3], $texts),
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    public function test_commit_runs_the_real_chain_to_succeeded_and_completed(): void
    {
        $admin = $this->makeAdmin();

        $batchId = $this->actingAs($admin)->post('/api/admin/kb/uploads', [
            'project_key' => 'engineering',
            'files' => [UploadedFile::fake()->createWithContent('integration-guide.md', "# Guide\n\nReal commit chain.")],
        ])->assertStatus(201)->json('batch.id');

        $itemId = KbIngestBatchItem::query()->where('batch_id', $batchId)->firstOrFail()->id;

        // Commit. Under the sync queue the job runs inline, the listener fires,
        // and finalizeBatchIfComplete settles the batch — all before the
        // response returns.
        $this->actingAs($admin)->postJson("/api/admin/kb/uploads/{$batchId}/commit")
            ->assertStatus(202)
            ->assertJsonPath('batch.status', KbIngestBatch::STATUS_COMPLETED)
            ->assertJsonPath('items.0.status', KbIngestBatchItem::STATUS_SUCCEEDED);

        // The item reconciled to succeeded and was deep-linked to its document.
        $item = KbIngestBatchItem::query()->whereKey($itemId)->firstOrFail();
        $this->assertSame(KbIngestBatchItem::STATUS_SUCCEEDED, $item->status);
        $this->assertNotNull($item->knowledge_document_id, 'observer should stamp the new doc id');

        // The batch finalized exactly once, with no failures.
        $batch = KbIngestBatch::query()->whereKey($batchId)->firstOrFail();
        $this->assertSame(KbIngestBatch::STATUS_COMPLETED, $batch->status);
        $this->assertNotNull($batch->finished_at);

        // The shared Artisan path actually ingested the document + moved the file.
        $this->assertTrue(Storage::disk('kb')->exists('integration-guide.md'));
        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $item->knowledge_document_id,
            'project_key' => 'engineering',
            'tenant_id' => 'default',
        ]);

        // The status endpoint the FE polls reflects the terminal counts.
        $resp = $this->actingAs($admin)->getJson("/api/admin/kb/uploads/{$batchId}/status")->assertOk();
        $this->assertSame(1, $resp->json('batch.counts.succeeded'));
        $this->assertSame(0, $resp->json('batch.counts.failed'));
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
