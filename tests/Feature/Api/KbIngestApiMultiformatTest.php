<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\KbIngestController;
use App\Jobs\IngestDocumentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Docx\DocxFixtureBuilder;
use Tests\Fixtures\Pdf\PdfFixtureBuilder;
use Tests\TestCase;

/**
 * T1.8 — verifies the multi-format API ingest contract:
 *   - default mime_type is 'text/markdown' (back-compat with pre-T1.8 callers)
 *   - explicit mime_type values get propagated to IngestDocumentJob
 *   - binary MIMEs require base64-encoded content (decoded before disk write)
 *   - unsupported MIME types return 422
 *
 * The job's actual execution + per-format conversion + chunking lives in:
 *   - tests/Feature/Jobs/IngestDocumentJobTest.php (manual job::handle())
 *   - tests/Feature/Kb/PdfIngestionTest.php / DocxIngestionTest.php / DocumentIngestorPipelineTest.php
 *   - tests/Feature/Console/KbIngestFolderMultiformatTest.php (sync end-to-end)
 *
 * Here we focus on the HTTP boundary: validation, payload decoding, job
 * dispatch contract.
 */
final class KbIngestApiMultiformatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');

        // Sanctum isn't loaded in the test harness; register the route
        // without auth middleware to exercise the controller logic in
        // isolation (mirrors the pattern in KbIngestControllerTest).
        Route::post('/api/kb/ingest', KbIngestController::class)->name('api.kb.ingest');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.ingest.queue', 'kb-ingest');
        config()->set('kb.ingest.default_project', 'default');
    }

    public function test_default_mime_type_is_text_markdown_back_compat(): void
    {
        Queue::fake();

        $resp = $this->postJson('/api/kb/ingest', [
            'documents' => [[
                'project_key' => 'api-mf-test',
                'source_path' => 'docs/legacy.md',
                'title' => 'Legacy',
                'content' => "# Hello\n\nbody.",
            ]],
        ]);

        $resp->assertStatus(202)
            ->assertJsonPath('queued', 1)
            ->assertJsonPath('documents.0.source_type', 'markdown');

        Storage::disk('kb')->assertExists('docs/legacy.md');
        Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => (
            $job->relativePath === 'docs/legacy.md'
            && $job->mimeType === 'text/markdown'
            && $job->title === 'Legacy'
        ));
    }

    public function test_pdf_mime_type_decodes_base64_writes_binary_and_dispatches_with_pdf_mime(): void
    {
        Queue::fake();
        $pdfBytes = PdfFixtureBuilder::buildThreePageSample();

        $resp = $this->postJson('/api/kb/ingest', [
            'documents' => [[
                'project_key' => 'api-mf-test',
                'source_path' => 'docs/report.pdf',
                'title' => 'Report',
                'mime_type' => 'application/pdf',
                'content' => base64_encode($pdfBytes),
            ]],
        ]);

        $resp->assertStatus(202)
            ->assertJsonPath('documents.0.source_type', 'pdf');

        // The disk MUST hold the raw decoded bytes (NOT the base64 string),
        // otherwise the job's converter would choke on encoded data.
        $this->assertSame($pdfBytes, Storage::disk('kb')->get('docs/report.pdf'));
        Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => (
            $job->relativePath === 'docs/report.pdf'
            && $job->mimeType === 'application/pdf'
        ));
    }

    public function test_docx_mime_type_decodes_base64_and_dispatches_with_docx_mime(): void
    {
        Queue::fake();
        $docxBytes = DocxFixtureBuilder::buildHeadingsSample();

        $resp = $this->postJson('/api/kb/ingest', [
            'documents' => [[
                'project_key' => 'api-mf-test',
                'source_path' => 'docs/spec.docx',
                'title' => 'Spec',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'content' => base64_encode($docxBytes),
            ]],
        ]);

        $resp->assertStatus(202)
            ->assertJsonPath('documents.0.source_type', 'docx');

        $this->assertSame($docxBytes, Storage::disk('kb')->get('docs/spec.docx'));
        Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => (
            $job->mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ));
    }

    public function test_text_plain_mime_type_does_not_require_base64(): void
    {
        Queue::fake();

        $resp = $this->postJson('/api/kb/ingest', [
            'documents' => [[
                'project_key' => 'api-mf-test',
                'source_path' => 'docs/notes.txt',
                'mime_type' => 'text/plain',
                'content' => 'Plain text body.',
            ]],
        ]);

        $resp->assertStatus(202)
            ->assertJsonPath('documents.0.source_type', 'text');

        // Non-binary MIMEs land on disk verbatim (no base64 decode step).
        $this->assertSame('Plain text body.', Storage::disk('kb')->get('docs/notes.txt'));
        Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => (
            $job->mimeType === 'text/plain'
        ));
    }

    public function test_rejects_unsupported_mime_type_with_422(): void
    {
        Queue::fake();

        $resp = $this->postJson('/api/kb/ingest', [
            'documents' => [[
                'project_key' => 'api-mf-test',
                'source_path' => 'docs/blob.bin',
                'mime_type' => 'application/octet-stream',
                'content' => 'whatever',
            ]],
        ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);

        Queue::assertNothingPushed();
    }

    public function test_rejects_invalid_base64_for_binary_mime_with_422(): void
    {
        Queue::fake();

        $resp = $this->postJson('/api/kb/ingest', [
            'documents' => [[
                'project_key' => 'api-mf-test',
                'source_path' => 'docs/badbase64.pdf',
                'mime_type' => 'application/pdf',
                // Whitespace + invalid b64 chars — strict base64_decode rejects.
                'content' => 'this is not valid base64 #$@!',
            ]],
        ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);

        Queue::assertNothingPushed();
    }
}
