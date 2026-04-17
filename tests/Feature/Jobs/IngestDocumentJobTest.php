<?php

namespace Tests\Feature\Jobs;

use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class IngestDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kb.embedding_cache.enabled', false);
        config()->set('ai.default', 'openai');
        config()->set('ai.embeddings_provider', 'openai');

        Http::fake([
            'api.openai.com/*' => function ($request) {
                $inputs = $request->data()['input'] ?? [];
                $data = [];
                foreach ($inputs as $i => $_text) {
                    $data[] = ['index' => $i, 'embedding' => [0.1, 0.2, 0.3]];
                }

                return Http::response([
                    'model' => 'text-embedding-3-small',
                    'data' => $data,
                    'usage' => ['total_tokens' => count($inputs)],
                ], 200);
            },
        ]);
    }

    public function test_handle_reads_from_disk_and_stores_document(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/hello.md', "# Hello\n\nBody paragraph.");
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'docs/hello.md',
            disk: 'kb',
            title: 'Hello Doc',
        );

        $job->handle(app(DocumentIngestor::class));

        $doc = KnowledgeDocument::first();
        $this->assertNotNull($doc);
        $this->assertSame('demo', $doc->project_key);
        $this->assertSame('Hello Doc', $doc->title);
        $this->assertSame('docs/hello.md', $doc->source_path);
    }

    public function test_handle_throws_when_file_is_missing_so_queue_retries(): void
    {
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'missing.md',
            disk: 'kb',
        );

        $this->expectException(RuntimeException::class);
        $job->handle(app(DocumentIngestor::class));
    }

    public function test_handle_respects_configured_path_prefix(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('tenant-a/docs/guide.md', "# Guide\n\nBody.");
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', 'tenant-a/');

        $job = new IngestDocumentJob(
            projectKey: 'tenant-a',
            relativePath: 'docs/guide.md',
            disk: 'kb',
        );

        $job->handle(app(DocumentIngestor::class));

        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_metadata_is_forwarded_to_ingestor(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/x.md', 'body');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'docs/x.md',
            disk: 'kb',
            metadata: ['language' => 'en', 'author' => 'alice'],
        );

        $job->handle(app(DocumentIngestor::class));

        $doc = KnowledgeDocument::first();
        $this->assertSame('en', $doc->language);
        $this->assertSame('alice', $doc->metadata['author'] ?? null);
        $this->assertSame('kb', $doc->metadata['disk'] ?? null);
    }

    public function test_queue_name_comes_from_config(): void
    {
        config()->set('kb.ingest.queue', 'custom-queue-name');

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'x.md',
            disk: 'kb',
        );

        $this->assertSame('custom-queue-name', $job->queue);
    }
}
