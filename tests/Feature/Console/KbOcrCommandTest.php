<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.36 / ADR 0029 — the PHP surface of OCR (R44) over the same OcrService.
 */
final class KbOcrCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        Storage::fake('kb');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '', 'kb.ocr.driver' => 'fake']);
    }

    private function doc(array $metadata = []): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => 'default',
            'project_key' => 'legal', 'source_type' => 'pdf', 'title' => 'Scan',
            'source_path' => 'scans/one.pdf', 'mime_type' => 'application/pdf', 'language' => 'en', 'access_scope' => 'internal',
            'status' => 'active', 'document_hash' => hash('sha256', 'one'), 'version_hash' => hash('sha256', 'one'),
            'metadata' => array_merge(['disk' => 'kb', 'prefix' => ''], $metadata),
        ]);
    }

    public function test_status_prints_the_recorded_facts(): void
    {
        config(['kb.ocr.enabled' => true]);
        $doc = $this->doc(['converter' => ['page_count' => 1, 'provenance' => 'ocr', 'ocr' => [
            'driver' => 'fake', 'reason' => 'scanned_pdf', 'mean_confidence' => 0.8, 'min_confidence' => 0.8, 'figures' => 0,
            'pages' => [['number' => 1, 'confidence' => 0.8, 'figures' => 0]],
        ]]]);

        $this->artisan('kb:ocr', ['document' => $doc->id, '--status' => true])
            // One expectation per written line: Laravel matches each
            // expectsOutputToContain against a DISTINCT writeln, in order.
            ->expectsOutputToContain('driver fake · reason scanned_pdf · pages 1')
            ->assertSuccessful();
    }

    public function test_a_blank_tenant_is_refused_before_any_lookup(): void
    {
        $this->artisan('kb:ocr', ['document' => 1, '--status' => true, '--tenant' => ' '])
            ->expectsOutputToContain('--tenant must be a non-empty tenant id.')
            ->assertExitCode(1);
    }

    public function test_status_for_a_document_without_ocr(): void
    {
        config(['kb.ocr.enabled' => false]);
        $doc = $this->doc();

        $this->artisan('kb:ocr', ['document' => $doc->id, '--status' => true])
            ->expectsOutputToContain('was not produced by OCR')
            ->assertSuccessful();
    }

    public function test_rerun_fails_when_ocr_is_off(): void
    {
        config(['kb.ocr.enabled' => false]);
        Queue::fake();
        $doc = $this->doc();

        $this->artisan('kb:ocr', ['document' => $doc->id])->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_rerun_queues_the_ingestion_with_ocr_forced(): void
    {
        config(['kb.ocr.enabled' => true]);
        Queue::fake();
        $doc = $this->doc();
        Storage::disk('kb')->put('scans/one.pdf', '%PDF-1.4');

        $this->artisan('kb:ocr', ['document' => $doc->id])
            ->expectsOutputToContain('OCR re-run queued')
            ->assertSuccessful();

        Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => ($job->metadata['ocr']['force'] ?? false) === true
            && $job->metadata['version_actor'] === 'cli:kb:ocr'
            && $job->runKey !== null);
    }

    public function test_unknown_document_fails(): void
    {
        $this->artisan('kb:ocr', ['document' => 999999, '--status' => true])->assertFailed();
    }

    public function test_a_queued_rerun_makes_a_second_cli_rerun_fail_cleanly(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'fake', 'kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '']);
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Storage::fake('kb');
        \Illuminate\Support\Facades\Storage::disk('kb')->put('docs/scan.png', (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true));
        $doc = \App\Models\KnowledgeDocument::create([
            'tenant_id' => 'default', 'project_key' => 'legal', 'source_type' => 'image', 'title' => 'scan',
            'source_path' => 'docs/scan.png', 'mime_type' => 'image/png', 'language' => 'en', 'access_scope' => 'internal',
            'status' => 'active', 'document_hash' => hash('sha256', 'x'), 'version_hash' => hash('sha256', 'x'),
            'metadata' => ['disk' => 'kb', 'prefix' => ''], 'indexed_at' => now(),
        ]);

        $this->artisan('kb:ocr', ['document' => $doc->id, '--tenant' => 'default'])->assertExitCode(0);
        $this->artisan('kb:ocr', ['document' => $doc->id, '--tenant' => 'default'])
            ->expectsOutputToContain('already queued')
            ->assertExitCode(1);
    }
}
