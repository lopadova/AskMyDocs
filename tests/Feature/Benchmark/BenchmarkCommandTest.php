<?php

declare(strict_types=1);

namespace Tests\Feature\Benchmark;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.2 WS2 — wiring test for `kb:benchmark`. Runs the command in --stub mode
 * (deterministic embeddings, SQLite + PHP-cosine, no key) and asserts it
 * drives the full pipeline + emits a structured scorecard + a persisted
 * report. Enterprise THRESHOLDS are validated by the LIVE run (WS5) with
 * real embeddings — the stub has no semantic quality, so we don't gate here.
 */
final class BenchmarkCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'sync',
            'kb.reranking.enabled' => true,
            'kb.hybrid_search.enabled' => false,
            'kb.graph.expansion_enabled' => true,
            'kb.rejected.injection_enabled' => true,
            'kb.default_min_similarity' => 0.05, // coarse stub embedder
        ]);
        app(TenantContext::class)->set('default');
        Storage::fake('local');
    }

    public function test_stub_benchmark_runs_full_pipeline_and_writes_a_scorecard(): void
    {
        $root = dirname(__DIR__, 3);

        $this->artisan('kb:benchmark', [
            '--stub' => true,
            '--corpus' => $root.'/resources/benchmark/corpus',
            '--queries' => $root.'/resources/benchmark/queries.yaml',
            '--project' => 'benchmark',
            '--k' => 5,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Retrieval-quality benchmark');

        // A dated JSON + MD report is persisted.
        $files = Storage::disk('local')->allFiles('kb-benchmark');
        $this->assertNotEmpty($files, 'a benchmark report was written');
        $json = collect($files)->first(fn ($f) => str_ends_with($f, '.json'));
        $this->assertNotNull($json);

        $card = json_decode(Storage::disk('local')->get($json), true);
        $this->assertSame(5, $card['corpus_count'], 'all 5 corpus docs ingested');
        $this->assertSame(14, $card['query_count'], 'all 14 labelled queries scored');
        $this->assertArrayHasKey('ndcg_at_k', $card['aggregate']);
        $this->assertArrayHasKey('citation_precision', $card['aggregate']);
        $this->assertArrayHasKey('refusal_accuracy', $card['aggregate']);
        // The stub still lexically ranks + cites cache docs for cache queries.
        $this->assertGreaterThan(0.0, $card['aggregate']['citation_precision']);
    }
}
