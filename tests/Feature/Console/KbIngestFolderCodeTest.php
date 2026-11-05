<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * v8.45 / ADR 0035 D7 — `kb:ingest-folder --pattern=php,ts` end to end in BOTH
 * flag states, with the embedding provider replaced by a recording fake so the
 * test can PROVE a secret never reaches the vectors.
 */
final class KbIngestFolderCodeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'AKIAIOSFODNN7EXAMPLE';

    /** @var list<string> every text handed to the embedding provider */
    private array $embedded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->embedded = [];
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(function (array $texts): EmbeddingsResponse {
            array_push($this->embedded, ...array_values($texts));

            return new EmbeddingsResponse(
                embeddings: array_map(static fn () => array_fill(0, 768, 0.0), $texts),
                provider: 'fake',
                model: 'fake-768',
            );
        });
        $cache->shouldReceive('activeTarget')->andReturn(['provider' => 'fake', 'model' => 'fake-768']);
        $this->app->instance(EmbeddingCacheService::class, $cache);

        config([
            'kb.code.chunker_driver' => 'heuristic',
            'kb.code.max_file_bytes' => 8192,
            'kb.code.extra_deny_globs' => [],
        ]);
        Storage::fake('kb');
        Storage::disk('kb')->put('repo/src/Billing/Invoice.php', "<?php\n\nnamespace App\\Billing;\n\nclass Invoice\n{\n    public function total(): int\n    {\n        \$aws = '".self::SECRET."';\n\n        return 42;\n    }\n}\n");
        Storage::disk('kb')->put('repo/web/api.ts', "export function load(id: string) {\n  return fetch('/x/' + id);\n}\n");
        Storage::disk('kb')->put('repo/vendor/acme/lib.php', "<?php\nclass Lib {}\n");
        Storage::disk('kb')->put('repo/package-lock.json', '{"lockfileVersion":3}');
        Storage::disk('kb')->put('repo/README.md', "# Repo\n\nA repository.\n");
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function ingest(string $pattern): int
    {
        return Artisan::call('kb:ingest-folder', [
            'path' => 'repo',
            '--project' => 'code-repo',
            '--sync' => true,
            '--recursive' => true,
            '--pattern' => $pattern,
        ]);
    }

    public function test_off_a_code_extension_is_unsupported_exactly_as_before(): void
    {
        config(['kb.code.ingestion_enabled' => false]);

        $exit = $this->ingest('php,ts');

        $this->assertSame(1, $exit, 'the files are listed then fail as unsupported (v8.44 behaviour)');
        $this->assertSame(0, KnowledgeDocument::query()->where('project_key', 'code-repo')->count());
        $this->assertStringContainsString('unsupported_extension', Artisan::output());
        $this->assertSame([], $this->embedded);
    }

    public function test_on_code_is_ingested_deny_listed_files_are_skipped_and_the_run_stays_green(): void
    {
        config(['kb.code.ingestion_enabled' => true]);

        $exit = $this->ingest('php,ts,json');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $docs = KnowledgeDocument::query()->where('project_key', 'code-repo')->orderBy('source_path')->get()->keyBy('source_path');
        $this->assertSame(['repo/src/Billing/Invoice.php', 'repo/web/api.ts'], $docs->keys()->all());
        $this->assertSame('code', $docs['repo/src/Billing/Invoice.php']->source_type);
        $this->assertSame('text/x-php', $docs['repo/src/Billing/Invoice.php']->mime_type);
        $this->assertSame('text/x-typescript', $docs['repo/web/api.ts']->mime_type);
        $this->assertStringContainsString('vendor/acme/lib.php', $output);
        $this->assertStringContainsString('repo/package-lock.json', $output);
        $this->assertStringContainsString('denied_path', $output);
    }

    public function test_on_code_chunks_carry_structure_metadata_and_secrets_never_reach_storage_or_embeddings(): void
    {
        config(['kb.code.ingestion_enabled' => true]);
        $this->ingest('php');

        $doc = KnowledgeDocument::query()->where('source_path', 'repo/src/Billing/Invoice.php')->firstOrFail();
        $chunks = KnowledgeChunk::query()->where('knowledge_document_id', $doc->id)->orderBy('chunk_order')->get();

        $this->assertNotEmpty($chunks);
        $first = $chunks[0];
        $this->assertSame('php', $first->metadata['language']);
        $this->assertSame('heuristic', $first->metadata['chunker']);
        $this->assertSame(1, $first->metadata['start_line']);
        $this->assertStringContainsString('Invoice', (string) $first->heading_path);

        foreach ($chunks as $chunk) {
            $this->assertStringNotContainsString(self::SECRET, $chunk->chunk_text);
        }
        $this->assertSame(1, $doc->metadata['converter']['secrets_redacted']);
        $this->assertNotSame([], $this->embedded);
        foreach ($this->embedded as $text) {
            $this->assertStringNotContainsString(self::SECRET, $text, 'a secret must never be sent to the embedding provider');
        }
        // The code header (file | symbol | lang | code) is used even though the prose template is off.
        $this->assertStringStartsWith('file: ', $this->embedded[0]);
        $this->assertStringContainsString('lang: php', $this->embedded[0]);
        // Nothing stored anywhere holds the secret: document row, metadata, artifact.
        $this->assertStringNotContainsString(self::SECRET, json_encode($doc->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_on_the_default_pattern_does_not_sweep_in_code(): void
    {
        config(['kb.code.ingestion_enabled' => true]);

        Artisan::call('kb:ingest-folder', ['path' => 'repo', '--project' => 'code-repo', '--sync' => true, '--recursive' => true]);

        $paths = KnowledgeDocument::query()->where('project_key', 'code-repo')->pluck('source_path')->all();
        $this->assertSame(['repo/README.md'], $paths, 'code needs an explicit --pattern, even with the flag on');
    }

    public function test_on_re_ingest_is_idempotent(): void
    {
        config(['kb.code.ingestion_enabled' => true]);
        $this->ingest('php');
        $before = KnowledgeChunk::query()->count();
        $this->ingest('php');

        $this->assertSame($before, KnowledgeChunk::query()->count());
        $this->assertSame(1, KnowledgeDocument::query()->where('source_path', 'repo/src/Billing/Invoice.php')->count());
    }

    public function test_dry_run_labels_code_files_with_their_source_type(): void
    {
        config(['kb.code.ingestion_enabled' => true]);

        Artisan::call('kb:ingest-folder', ['path' => 'repo', '--project' => 'code-repo', '--dry-run' => true, '--recursive' => true, '--pattern' => 'php']);

        $this->assertStringContainsString('repo/src/Billing/Invoice.php [code]', Artisan::output());
    }
}
