<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v8.36 / ADR 0029 — HTTP surface of OCR (R44): status + re-run, tenant-scoped
 * (R30), both flag states (R43).
 */
final class KbOcrControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('kb');
        config([
            'kb.sources.disk' => 'kb',
            'kb.sources.path_prefix' => '',
            'kb.ocr.driver' => 'fake',
        ]);
    }

    private function makeAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function document(string $mime = 'application/pdf', array $metadata = [], ?string $path = null): KnowledgeDocument
    {
        $path ??= 'scans/'.uniqid().'.'.($mime === 'application/pdf' ? 'pdf' : 'md');
        $sourceType = match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'image',
            default => 'markdown',
        };

        return KnowledgeDocument::create([
            'project_key' => 'legal',
            'source_type' => $sourceType,
            'title' => 'Doc',
            'source_path' => $path,
            'mime_type' => $mime,
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $path),
            'version_hash' => hash('sha256', $path),
            'metadata' => array_merge(['disk' => 'kb', 'prefix' => ''], $metadata),
            'indexed_at' => now(),
        ]);
    }

    public function test_status_reports_a_document_that_was_not_produced_by_ocr(): void
    {
        config(['kb.ocr.enabled' => false]);
        $doc = $this->document();

        $this->actingAs($this->makeAdmin())->getJson("/api/admin/kb/documents/{$doc->id}/ocr")
            ->assertOk()
            ->assertJsonPath('data.document_id', $doc->id)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.ocr', false)
            ->assertJsonPath('data.driver_configured', 'fake')
            ->assertJsonPath('data.pages', []);
    }

    public function test_status_reports_what_ocr_recorded(): void
    {
        config(['kb.ocr.enabled' => true]);
        $doc = $this->document('application/pdf', ['converter' => [
            'converter' => 'pdf-converter', 'page_count' => 2, 'provenance' => 'ocr',
            'ocr' => ['driver' => 'fake', 'remote' => false, 'reason' => 'scanned_pdf', 'mean_confidence' => 0.75, 'min_confidence' => 0.6, 'figures' => 1, 'figures_dir' => 'x.ocr', 'ran_at' => '2026-09-12T10:00:00+00:00',
                'pages' => [['number' => 1, 'confidence' => 0.9, 'figures' => 1], ['number' => 2, 'confidence' => 0.6, 'figures' => 0]]],
        ]]);

        $this->actingAs($this->makeAdmin())->getJson("/api/admin/kb/documents/{$doc->id}/ocr")
            ->assertOk()
            ->assertJsonPath('data.ocr', true)
            ->assertJsonPath('data.driver', 'fake')
            ->assertJsonPath('data.reason', 'scanned_pdf')
            ->assertJsonPath('data.remote', false)
            ->assertJsonPath('data.page_count', 2)
            ->assertJsonPath('data.mean_confidence', 0.75)
            ->assertJsonPath('data.pages.1.confidence', 0.6)
            ->assertJsonPath('data.figures', 1);
    }

    public function test_status_is_tenant_scoped(): void
    {
        $doc = $this->document();
        app(TenantContext::class)->set('other-tenant');
        $other = $this->document();
        app(TenantContext::class)->reset();

        $this->actingAs($this->makeAdmin())->getJson("/api/admin/kb/documents/{$other->id}/ocr")->assertNotFound();
        $this->actingAs($this->makeAdmin())->getJson("/api/admin/kb/documents/{$doc->id}/ocr")->assertOk();
    }

    public function test_rerun_is_refused_when_ocr_is_off(): void
    {
        config(['kb.ocr.enabled' => false]);
        Queue::fake();
        $doc = $this->document();

        $this->actingAs($this->makeAdmin())->postJson("/api/admin/kb/documents/{$doc->id}/ocr")
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_rerun_is_refused_for_a_non_ocr_able_document(): void
    {
        config(['kb.ocr.enabled' => true]);
        Queue::fake();
        $doc = $this->document('text/markdown');

        $this->actingAs($this->makeAdmin())->postJson("/api/admin/kb/documents/{$doc->id}/ocr")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_rerun_is_refused_when_the_source_file_is_gone(): void
    {
        config(['kb.ocr.enabled' => true]);
        Queue::fake();
        $doc = $this->document();

        $this->actingAs($this->makeAdmin())->postJson("/api/admin/kb/documents/{$doc->id}/ocr")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_rerun_is_a_422_not_a_queued_failure_when_a_remote_driver_would_refuse_the_page_count(): void
    {
        // R14 — the job would refuse an unverifiable page count on a remote
        // driver (ADR 0029 §4); the pre-flight says so now, and the re-run
        // lock is never taken, so a corrected retry is not a 409.
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k']);
        Queue::fake();
        $doc = $this->document();
        Storage::disk('kb')->put($doc->source_path, '%PDF-1.4 not really a pdf');

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$doc->id}/ocr")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'OCR refused for "'.basename($doc->source_path).'": the page count could not be verified (the PDF could not be parsed) and driver "mistral-ocr" is remote; an unverifiable document only runs where the work is bounded by construction.']);
        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$doc->id}/ocr")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    /**
     * The re-run resolves the source with the same normaliser the ingest used:
     * a prefix with backslashes or repeated separators reads the same object,
     * never a false "not found".
     */
    public function test_rerun_resolves_the_source_through_the_normalised_prefix(): void
    {
        config(['kb.ocr.enabled' => true]);
        Queue::fake();
        $doc = $this->document('application/pdf', ['disk' => 'kb', 'prefix' => 'sub\\dir//']);
        Storage::disk('kb')->put('sub/dir/'.$doc->source_path, '%PDF-1.4 x');

        $this->actingAs($this->makeAdmin())->postJson("/api/admin/kb/documents/{$doc->id}/ocr")->assertSuccessful();
        Queue::assertPushed(\App\Jobs\IngestDocumentJob::class, 1);
    }

    public function test_rerun_queues_the_ingestion_with_ocr_forced_and_a_fresh_run_key(): void
    {
        config(['kb.ocr.enabled' => true]);
        Queue::fake();
        $doc = $this->document();
        Storage::disk('kb')->put($doc->source_path, '%PDF-1.4 scanned');

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$doc->id}/ocr")
            ->assertStatus(202)
            ->assertJsonPath('data.dispatched', true)
            ->assertJsonPath('data.document_id', $doc->id)
            ->assertJsonPath('data.driver', 'fake')
            // The Flow idempotency salt of this dispatch — the OCR run key is
            // content-addressed and exists only once the job has run.
            ->assertJsonMissingPath('data.run_key');
        $this->assertStringStartsWith('ocr:', (string) $response->json('data.flow_run_key'));

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) use ($doc, $admin): bool {
            return $job->relativePath === $doc->source_path
                && $job->mimeType === 'application/pdf'
                && ($job->metadata['ocr']['force'] ?? false) === true
                && $job->metadata['version_actor'] === 'user:'.$admin->id
                && str_starts_with((string) $job->runKey, 'ocr:')
                && $job->tenantId === app(TenantContext::class)->current();
        });
    }

    public function test_rerun_is_tenant_scoped(): void
    {
        config(['kb.ocr.enabled' => true]);
        Queue::fake();
        app(TenantContext::class)->set('other-tenant');
        $other = $this->document();
        app(TenantContext::class)->reset();

        $this->actingAs($this->makeAdmin())->postJson("/api/admin/kb/documents/{$other->id}/ocr")->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_a_second_rerun_while_one_is_queued_is_a_409(): void
    {
        config(['kb.ocr.enabled' => true]);
        \Illuminate\Support\Facades\Queue::fake();
        Storage::fake('kb');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '']);
        Storage::disk('kb')->put('docs/scan.png', (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true));
        $doc = $this->document('image/png', ['disk' => 'kb', 'prefix' => ''], 'docs/scan.png');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$doc->id}/ocr")->assertStatus(202);
        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$doc->id}/ocr")->assertStatus(409);

        // The queued job releases the lock it carried; a later re-run is accepted.
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\IngestDocumentJob::class, function (\App\Jobs\IngestDocumentJob $job): bool {
            \App\Services\Kb\Ocr\OcrService::releaseRerunLock($job->metadata);

            return isset($job->metadata['ocr']['rerun_lock']['owner']);
        });
        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$doc->id}/ocr")->assertStatus(202);
    }


    /**
     * R14 — a remote driver with KB_OCR_ALLOW_REMOTE off is the same "cannot
     * run here" as an unavailable one: a 422 with the registry's reason,
     * never a 500 from `OcrDriverUnavailableException` (Copilot #478 round 3).
     */
    public function test_rerun_is_a_422_not_a_500_when_the_configured_driver_name_is_unknown(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'tesseratc']);
        Queue::fake();
        $doc = $this->document();
        Storage::disk('kb')->put($doc->source_path, '%PDF-1.4 x');

        $this->actingAs($this->makeAdmin())->postJson("/api/admin/kb/documents/{$doc->id}/ocr")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Unknown OCR driver "tesseratc". Registered: docling, mistral-ocr, vision-llm, tesseract, fake.']);
        Queue::assertNothingPushed();
    }

    public function test_rerun_is_a_422_not_a_500_when_the_configured_driver_is_remote_and_remote_is_not_allowed(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => false, 'kb.ocr.mistral.api_key' => 'k']);
        Queue::fake();
        $doc = $this->document();
        Storage::disk('kb')->put($doc->source_path, '%PDF-1.4 x');

        $this->actingAs($this->makeAdmin())->postJson("/api/admin/kb/documents/{$doc->id}/ocr")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'OCR driver "mistral-ocr" sends documents to a remote service and is disabled; set KB_OCR_ALLOW_REMOTE=true to allow it.']);
        Queue::assertNothingPushed();
    }
}
