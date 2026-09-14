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

        // Generate as many embeddings as the request contains inputs. The
        // MarkdownChunker splits by blank lines — the number of chunks varies
        // depending on line endings (LF on Linux, CRLF on Windows), so a
        // static fixture would be flaky across CI runners.
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

    public function test_reads_markdown_via_disk_and_ingests_document(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/hello.md', "# Title\n\nBody paragraph.");

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

    /** R14 — a refused artifact publish is one error line naming the committed document and the repair, never a stack trace. */
    public function test_a_refused_artifact_publish_is_reported_with_the_committed_document_and_exits_non_zero(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/refused.md', "# Title\n\nBody paragraph.");
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.conversion_artifacts.enabled', true);
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => str_contains($path, '.versions/') && str_ends_with($path, '.md'));
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));

        try {
            $this->artisan('kb:ingest', ['path' => 'docs/refused.md', '--project' => 'demo'])
                ->expectsOutputToContain('was ingested from kb://docs/refused.md, but its conversion artifact could not be published on disk [kb]')
                ->assertExitCode(1);
        } finally {
            Storage::set('kb', $healthy);
        }

        $doc = KnowledgeDocument::first();
        $this->assertNotNull($doc, 'the document is committed; only its artifact is missing');
        $this->assertNotNull($doc->markdown_path);
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
