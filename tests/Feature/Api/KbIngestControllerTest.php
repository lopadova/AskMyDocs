<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\KbIngestController;
use App\Jobs\IngestDocumentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KbIngestControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum isn't loaded in the test harness; register the route without
        // auth middleware to exercise the controller logic in isolation. The
        // auth wiring is identical to /api/kb/chat which is already exercised.
        Route::post('/api/kb/ingest', KbIngestController::class)->name('api.kb.ingest');

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.ingest.queue', 'kb-ingest');
        config()->set('kb.ingest.default_project', 'default');
    }

    public function test_rejects_empty_payload_with_422(): void
    {
        Queue::fake();

        $this->postJson('/api/kb/ingest', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);

        Queue::assertNothingPushed();
    }

    public function test_rejects_invalid_document_shape_with_422(): void
    {
        Queue::fake();

        $this->postJson('/api/kb/ingest', [
            'documents' => [
                ['source_path' => 'docs/a.md'], // content missing
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents.0.content']);

        Queue::assertNothingPushed();
    }

    public function test_accepts_single_document_writes_to_disk_and_queues_job(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $response = $this->postJson('/api/kb/ingest', [
            'documents' => [
                [
                    'project_key' => 'erp-core',
                    'source_path' => 'docs/auth/oauth.md',
                    'title' => 'OAuth Setup',
                    'content' => "# OAuth\n\nBody.",
                    'metadata' => ['language' => 'en'],
                ],
            ],
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'queued' => 1,
                'documents' => [
                    [
                        'project_key' => 'erp-core',
                        'source_path' => 'docs/auth/oauth.md',
                        'status' => 'queued',
                    ],
                ],
            ]);

        Storage::disk('kb')->assertExists('docs/auth/oauth.md');
        Queue::assertPushed(IngestDocumentJob::class, 1);
        Queue::assertPushed(IngestDocumentJob::class, function ($job) {
            return $job->projectKey === 'erp-core'
                && $job->relativePath === 'docs/auth/oauth.md'
                && $job->disk === 'kb'
                && $job->title === 'OAuth Setup'
                && ($job->metadata['language'] ?? null) === 'en';
        });
    }

    public function test_accepts_batch_and_queues_one_job_per_document(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $documents = [];
        for ($i = 0; $i < 5; $i++) {
            $documents[] = [
                'project_key' => 'proj',
                'source_path' => "docs/file-{$i}.md",
                'content' => "file {$i}",
            ];
        }

        $this->postJson('/api/kb/ingest', ['documents' => $documents])
            ->assertStatus(202)
            ->assertJson(['queued' => 5]);

        Queue::assertPushed(IngestDocumentJob::class, 5);
        for ($i = 0; $i < 5; $i++) {
            Storage::disk('kb')->assertExists("docs/file-{$i}.md");
        }
    }

    public function test_uses_default_project_when_project_key_is_omitted(): void
    {
        Queue::fake();
        Storage::fake('kb');
        config()->set('kb.ingest.default_project', 'fallback');

        $this->postJson('/api/kb/ingest', [
            'documents' => [
                ['source_path' => 'x.md', 'content' => 'x'],
            ],
        ])->assertStatus(202);

        Queue::assertPushed(IngestDocumentJob::class, function ($job) {
            return $job->projectKey === 'fallback';
        });
    }

    public function test_applies_path_prefix_when_writing_to_disk(): void
    {
        Queue::fake();
        Storage::fake('kb');
        config()->set('kb.sources.path_prefix', 'tenant-a/');

        $this->postJson('/api/kb/ingest', [
            'documents' => [
                ['source_path' => 'docs/a.md', 'content' => 'hello'],
            ],
        ])->assertStatus(202);

        // Content is written under the configured prefix,
        // but the job carries the un-prefixed relative path.
        Storage::disk('kb')->assertExists('tenant-a/docs/a.md');
        Queue::assertPushed(IngestDocumentJob::class, function ($job) {
            return $job->relativePath === 'docs/a.md';
        });
    }

    public function test_enforces_max_batch_size_of_100(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $documents = [];
        for ($i = 0; $i < 101; $i++) {
            $documents[] = ['source_path' => "f{$i}.md", 'content' => 'x'];
        }

        $this->postJson('/api/kb/ingest', ['documents' => $documents])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);

        Queue::assertNothingPushed();
    }
}
