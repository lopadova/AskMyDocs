<?php

namespace Tests\Feature\Commands;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
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

    /**
     * v8.36 / PR #479 Copilot review round 3 — `KbIngestController` and
     * `ListFolderFilesStep` already reject a generated-asset source path
     * (`.artifacts/`, `{x}.ocr/`) before a row is ever created. `kb:ingest`
     * had no such guard: an operator (or a script) pointing it at the store's
     * own converted output would self-ingest it, composing a nested artifact
     * one level deeper under itself. The check runs before any disk read —
     * the file must never even be opened.
     */
    public function test_refuses_a_generated_asset_path_as_a_source(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('.artifacts/default/eng/docs/report.md.versions/'.str_repeat('a', 64).'.md', '# converted');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $this->artisan('kb:ingest', [
            'path' => '.artifacts/default/eng/docs/report.md.versions/'.str_repeat('a', 64).'.md',
        ])
            ->expectsOutputToContain('generated-asset directory')
            ->assertFailed();

        $this->assertSame(0, KnowledgeDocument::count());
    }

    /**
     * v8.36 / PR #479 Copilot review round 4 — R1: every KB source path
     * goes through `KbPath::normalize()` before any disk op or path-shape
     * decision, the same contract the HTTP/folder entry points already
     * follow. A raw `..` segment must never reach `Storage::exists()`/
     * `get()` at all — a clean, normalized-path error instead of a
     * traversal attempt or an uncaught driver exception outside R14's
     * one-line handling.
     */
    public function test_refuses_a_source_path_with_traversal_segments(): void
    {
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $this->artisan('kb:ingest', [
            'path' => 'docs/../outside.md',
        ])
            ->expectsOutputToContain('Invalid source path')
            ->assertFailed();

        $this->assertSame(0, KnowledgeDocument::count());
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

    /** R14 — `exists()` said yes, `get()` returned nothing: one error line, exit 1, no empty version. */
    public function test_fails_cleanly_when_the_file_has_no_bytes(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('empty.md', '');
        config()->set('kb.sources.disk', 'kb');

        $this->artisan('kb:ingest', [
            'path' => 'empty.md',
        ])
            ->expectsOutputToContain('returned no bytes')
            ->assertFailed();

        $this->assertSame(0, KnowledgeDocument::count());
    }

    /** R14 — exists() says yes but get() throws (a file that vanished, or a driver refusing the read): a clean one-line failure, never a stack trace out of a CLI command. */
    public function test_fails_cleanly_when_get_throws(): void
    {
        config()->set('kb.sources.disk', 'kb');

        Storage::shouldReceive('disk')->with('kb')->andReturn(new class
        {
            public function exists(string $path): bool
            {
                return true;
            }

            public function get(string $path): string
            {
                throw new \RuntimeException("vanished: {$path}");
            }
        });

        $this->artisan('kb:ingest', [
            'path' => 'vanished.md',
        ])
            ->expectsOutputToContain('could not be read')
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

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
