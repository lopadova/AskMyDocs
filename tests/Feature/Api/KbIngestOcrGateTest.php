<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\KbIngestController;
use App\Jobs\IngestDocumentJob;
use App\Services\Kb\Ocr\Drivers\FakeOcrDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.36 / ADR 0029 — the HTTP ingest entry point in BOTH flag states (R43):
 * an image is the same 422 as v8.35 with OCR off, and a queued binary
 * ingest with OCR on.
 */
final class KbIngestOcrGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        Route::post('/api/kb/ingest', KbIngestController::class)->name('api.kb.ingest');
        config([
            'kb.sources.disk' => 'kb',
            'kb.sources.path_prefix' => '',
            'kb.ingest.queue' => 'kb-ingest',
            'kb.ingest.default_project' => 'default',
        ]);
    }

    private function payload(): array
    {
        return ['documents' => [[
            'project_key' => 'legal',
            'source_path' => 'scans/letter.png',
            'title' => 'Letter',
            'mime_type' => 'image/png',
            'content' => FakeOcrDriver::PNG_1X1,
        ]]];
    }

    public function test_off_an_image_is_refused_with_the_v835_422_and_is_not_listed_as_supported(): void
    {
        config(['kb.ocr.enabled' => false]);
        Queue::fake();

        $resp = $this->postJson('/api/kb/ingest', $this->payload())->assertStatus(422);

        $message = (string) $resp->json('errors.documents.0');
        $this->assertStringContainsString('Unsupported mime_type "image/png"', $message);
        $this->assertStringNotContainsString('image/', substr($message, strpos($message, 'Supported:')));
        Storage::disk('kb')->assertMissing('scans/letter.png');
        Queue::assertNothingPushed();
    }

    public function test_on_an_image_is_written_as_binary_and_queued_with_its_mime(): void
    {
        config(['kb.ocr.enabled' => true]);
        Queue::fake();

        $this->postJson('/api/kb/ingest', $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('queued', 1)
            ->assertJsonPath('documents.0.source_type', 'image');

        Storage::disk('kb')->assertExists('scans/letter.png');
        $this->assertSame((string) base64_decode(FakeOcrDriver::PNG_1X1, true), Storage::disk('kb')->get('scans/letter.png'));
        Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => $job->mimeType === 'image/png' && $job->relativePath === 'scans/letter.png');
    }

    public function test_on_an_image_still_requires_base64_content(): void
    {
        config(['kb.ocr.enabled' => true]);
        Queue::fake();
        $payload = $this->payload();
        $payload['documents'][0]['content'] = 'not base64 !!!';

        $this->postJson('/api/kb/ingest', $payload)->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_on_the_supported_list_names_the_image_mimes(): void
    {
        config(['kb.ocr.enabled' => true]);
        $payload = $this->payload();
        $payload['documents'][0]['mime_type'] = 'image/gif';

        $message = (string) $this->postJson('/api/kb/ingest', $payload)->assertStatus(422)->json('errors.documents.0');
        $this->assertStringContainsString('image/png', $message);
    }
}
