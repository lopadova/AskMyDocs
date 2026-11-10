<?php

declare(strict_types=1);

namespace Tests\Feature\Benchmark;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * v8.41/W1 (ADR 0035 §D1) — benchmark v2: the suite is well-formed, the runner
 * understands `--suite=v2`, reports a per-class breakdown, and v1 is untouched.
 */
final class BenchmarkV2Test extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
        config([
            'queue.default' => 'sync',
            'kb.reranking.enabled' => true,
            'kb.hybrid_search.enabled' => false,
            'kb.graph.expansion_enabled' => true,
            'kb.rejected.injection_enabled' => true,
            'kb.default_min_similarity' => 0.05,
        ]);
        app(TenantContext::class)->set('default');
        Storage::fake('local');
    }

    // ── static well-formedness of the labelled set ───────────────────────

    /** @return array<string,mixed> */
    private function queries(): array
    {
        return Yaml::parseFile($this->root.'/resources/benchmark/v2/queries.yaml');
    }

    /** @return list<string> doc keys present in the corpus dir */
    private function corpusKeys(): array
    {
        $keys = [];
        foreach (glob($this->root.'/resources/benchmark/v2/corpus/*') ?: [] as $f) {
            $keys[] = pathinfo($f, PATHINFO_FILENAME);
        }

        return $keys;
    }

    public function test_corpus_keys_are_unique_across_extensions(): void
    {
        $keys = $this->corpusKeys();

        $this->assertSame(count($keys), count(array_unique($keys)), 'a basename collides across extensions');
        $this->assertGreaterThanOrEqual(40, count($keys));
    }

    public function test_query_ids_are_unique_and_every_referenced_doc_exists(): void
    {
        $queries = $this->queries()['queries'];
        $ids = array_column($queries, 'id');
        $keys = $this->corpusKeys();

        $this->assertSame(count($ids), count(array_unique($ids)), 'duplicate query id');

        foreach ($queries as $q) {
            $refs = array_merge(
                array_keys((array) ($q['relevance'] ?? [])),
                (array) ($q['expect_related'] ?? []),
                (array) ($q['expect_rejected'] ?? []),
                isset($q['expect_citation']) ? [$q['expect_citation']] : [],
            );
            foreach ($refs as $ref) {
                $this->assertContains($ref, $keys, "query [{$q['id']}] references missing doc [{$ref}]");
            }
        }
    }

    public function test_class_minimums_are_met(): void
    {
        $counts = array_count_values(array_column($this->queries()['queries'], 'class'));
        $minimums = ['paraphrase' => 16, 'hard_negative' => 16, 'identifier' => 10, 'context_dependent' => 10, 'short_ambiguous' => 10, 'code' => 10, 'refusal' => 10];

        foreach ($minimums as $class => $min) {
            $this->assertGreaterThanOrEqual($min, $counts[$class] ?? 0, "class [{$class}] below its minimum");
        }
        $this->assertGreaterThanOrEqual(86, array_sum($counts));
        $this->assertSame([], array_diff(array_keys($counts), array_keys($minimums)), 'unknown class label');
    }

    public function test_both_languages_are_represented(): void
    {
        $langs = array_count_values(array_column($this->queries()['queries'], 'lang'));

        $this->assertGreaterThanOrEqual(30, $langs['it'] ?? 0);
        $this->assertGreaterThanOrEqual(30, $langs['en'] ?? 0);
    }

    public function test_no_secret_shaped_string_is_in_the_corpus(): void
    {
        foreach (glob($this->root.'/resources/benchmark/v2/corpus/*.{md,php,ts,py}', GLOB_BRACE) ?: [] as $file) {
            $text = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/(sk_live_|sk-proj-|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|ghp_[A-Za-z0-9]{20,})/', $text, basename($file));
        }
    }

    // ── the runner ───────────────────────────────────────────────────────

    public function test_suite_v2_runs_reports_by_class_and_skips_code_queries_when_code_ingestion_is_off(): void
    {
        config(['kb.code.ingestion_enabled' => false]);

        // base_path() is Testbench's skeleton here, so pass the paths the real
        // app derives from --suite=v2 explicitly (the v1 test does the same).
        $this->artisan('kb:benchmark', [
            '--stub' => true,
            '--suite' => 'v2',
            '--corpus' => $this->root.'/resources/benchmark/v2/corpus',
            '--queries' => $this->root.'/resources/benchmark/v2/queries.yaml',
            '--project' => 'benchmark-v2',
            '--k' => 5,
        ])
            ->expectsOutputToContain('were SKIPPED')
            ->expectsOutputToContain('By class')
            ->assertExitCode(0);

        $json = collect(Storage::disk('local')->allFiles('kb-benchmark'))->first(fn ($f) => str_ends_with($f, '.json'));
        $card = json_decode(Storage::disk('local')->get($json), true);

        $this->assertSame('v2', $card['suite']);
        $this->assertSame(0, $card['corpus_failures'] === [] ? 0 : count($card['corpus_failures']), 'every non-code corpus file ingested');
        $this->assertNotSame([], $card['by_class']);
        $this->assertArrayHasKey('paraphrase', $card['by_class']);
        $this->assertArrayNotHasKey('code', $card['by_class'], 'skipped, not scored as a false zero');
        $this->assertCount(10, $card['skipped_queries']);
        $this->assertSame($card['query_count'], array_sum(array_column($card['by_class'], 'count')));
    }

    public function test_suite_v2_with_code_ingestion_on_ingests_the_code_corpus_and_scores_the_code_class(): void
    {
        config([
            'kb.code.ingestion_enabled' => true,
            'kb.code.chunker_driver' => 'heuristic',
        ]);

        $this->artisan('kb:benchmark', [
            '--stub' => true,
            '--suite' => 'v2',
            '--corpus' => $this->root.'/resources/benchmark/v2/corpus',
            '--queries' => $this->root.'/resources/benchmark/v2/queries.yaml',
            '--project' => 'benchmark-v2',
            '--k' => 5,
        ])->assertExitCode(0);

        $json = collect(Storage::disk('local')->allFiles('kb-benchmark'))->first(fn ($f) => str_ends_with($f, '.json'));
        $card = json_decode(Storage::disk('local')->get($json), true);

        $this->assertSame([], $card['corpus_failures'], 'the .php / .ts / .py corpus files ingest through the real code pipeline');
        $this->assertSame([], $card['skipped_queries']);
        $this->assertArrayHasKey('code', $card['by_class']);
        $this->assertSame(10, $card['by_class']['code']['count']);
        // The code files exist as code documents, not as prose.
        $this->assertSame(
            ['code'],
            \App\Models\KnowledgeDocument::query()->where('project_key', 'benchmark-v2')->whereIn('source_path', ['stock-reservation.php', 'route-optimizer.ts', 'retention-sweeper.py'])->pluck('source_type')->unique()->values()->all(),
        );
    }

    public function test_v2_stub_baseline_passes_its_own_gate_and_a_raised_floor_fails_it(): void
    {
        config(['kb.code.ingestion_enabled' => false]);
        $args = [
            '--stub' => true,
            '--suite' => 'v2',
            '--gate' => true,
            '--corpus' => $this->root.'/resources/benchmark/v2/corpus',
            '--queries' => $this->root.'/resources/benchmark/v2/queries.yaml',
            '--project' => 'benchmark-v2',
            '--k' => 5,
        ];

        // The committed floor (baseline − 0.02) holds on the committed baseline…
        $this->artisan('kb:benchmark', $args)->assertExitCode(0);

        // …and is a REAL floor: raise it above what the stub can reach and the gate fails.
        config(['kb.benchmark.v2.threshold_ndcg' => 0.99]);
        $this->artisan('kb:benchmark', $args)->expectsOutputToContain('gate failed')->assertExitCode(1);
    }

    public function test_an_unknown_suite_is_refused(): void
    {
        $this->artisan('kb:benchmark', ['--stub' => true, '--suite' => 'v9'])
            ->expectsOutputToContain('Unknown --suite')
            ->assertExitCode(1);
    }

    public function test_v1_scorecard_keeps_its_shape_and_gains_only_additive_keys(): void
    {
        $this->artisan('kb:benchmark', [
            '--stub' => true,
            '--corpus' => $this->root.'/resources/benchmark/corpus',
            '--queries' => $this->root.'/resources/benchmark/queries.yaml',
            '--project' => 'benchmark',
        ])->assertExitCode(0);

        $json = collect(Storage::disk('local')->allFiles('kb-benchmark'))->first(fn ($f) => str_ends_with($f, '.json'));
        $card = json_decode(Storage::disk('local')->get($json), true);

        $this->assertSame('v1', $card['suite']);
        $this->assertSame([], $card['by_class'], 'v1 queries carry no class label');
        $this->assertSame([], $card['skipped_queries']);
        $this->assertSame(14, $card['query_count']);
    }
}
