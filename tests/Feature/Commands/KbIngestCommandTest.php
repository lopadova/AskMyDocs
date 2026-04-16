<?php

namespace Tests\Feature\Commands;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KbIngestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable cache so DocumentIngestor calls AiManager directly,
        // which in turn hits the faked Http layer.
        config()->set('kb.embedding_cache.enabled', false);
        config()->set('ai.default', 'openai');
        config()->set('ai.embeddings_provider', 'openai');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'text-embedding-3-small',
                'data' => [
                    ['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ],
                'usage' => ['total_tokens' => 3],
            ], 200),
        ]);
    }

    public function test_reads_markdown_via_disk_and_ingests_document(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/hello.md', '# Title'.PHP_EOL.PHP_EOL.'Body paragraph.');

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $this->artisan('kb:ingest', [
            'path' => 'docs/hello.md',
            '--project' => 'demo',
            '--title' => 'Hello Doc',
        ])
            ->expectsOutputToContain('Ingested document')
            ->assertSuccessful();

        $doc = KnowledgeDocument::first();
        $this->assertNotNull($doc);
        $this->assertSame('demo', $doc->project_key);
        $this->assertSame('Hello Doc', $doc->title);
        $this->assertSame('docs/hello.md', $doc->source_path);
        $this->assertGreaterThan(0, KnowledgeChunk::count());
    }

    public function test_applies_configured_path_prefix(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('project-a/docs/guide.md', 'Just some content.');

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', 'project-a/');

        $this->artisan('kb:ingest', [
            'path' => 'docs/guide.md',
            '--project' => 'project-a',
        ])->assertSuccessful();

        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_fails_cleanly_when_file_missing(): void
    {
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');

        $this->artisan('kb:ingest', [
            'path' => 'missing.md',
        ])
            ->expectsOutputToContain('Markdown file not found')
            ->assertFailed();

        $this->assertSame(0, KnowledgeDocument::count());
    }

    public function test_disk_cli_option_overrides_config(): void
    {
        Storage::fake('other');
        Storage::disk('other')->put('x.md', 'yo');

        config()->set('kb.sources.disk', 'kb');  // default wrong
        config()->set('kb.sources.path_prefix', '');

        $this->artisan('kb:ingest', [
            'path' => 'x.md',
            '--disk' => 'other',
        ])->assertSuccessful();

        $this->assertSame(1, KnowledgeDocument::count());
    }
}
