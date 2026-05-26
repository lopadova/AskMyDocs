<?php

declare(strict_types=1);

namespace Tests\Feature\Benchmark;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_with_answers_generates_real_chat_and_scores_answer_faithfulness(): void
    {
        // --with-answers drives a REAL chat call per answerable query and
        // scores cosine(answer, cited-chunks). Fake the LLM (the only
        // external boundary) so the wiring is deterministic; embeddings stay
        // on the stub. The faked answer shares cache vocabulary with the
        // cited chunks so faithfulness is provably > 0.
        config([
            'ai.default' => 'openai',
            'ai.providers.openai.api_key' => 'sk-test',
            'ai.providers.openai.chat_model' => 'gpt-4o-mini',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [[
                    'message' => ['content' => 'Cache invalidation uses a TTL and an event-based purge of Redis keys, per the runbook.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $root = dirname(__DIR__, 3);

        $this->artisan('kb:benchmark', [
            '--stub' => true,
            '--with-answers' => true,
            '--corpus' => $root.'/resources/benchmark/corpus',
            '--queries' => $root.'/resources/benchmark/queries.yaml',
            '--project' => 'benchmark',
            '--k' => 5,
        ])->assertExitCode(0);

        // The chat boundary was actually exercised.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/chat/completions'));

        $files = Storage::disk('local')->allFiles('kb-benchmark');
        $json = collect($files)->first(fn ($f) => str_ends_with($f, '.json'));
        $card = json_decode(Storage::disk('local')->get($json), true);

        $this->assertArrayHasKey('answer_faithfulness', $card['aggregate']);

        // Prove the per-query mechanism ran: at least one answerable query must
        // have a non-null faithfulness (meaning answerFaithfulness() was called
        // AND returned a float). Asserting the aggregate alone could trivially
        // pass if the mean helper silently skipped everything (R16).
        $scored = array_filter($card['queries'], fn ($r) => isset($r['faithfulness']) && $r['faithfulness'] !== null);
        $this->assertNotEmpty($scored, 'at least one query must have a per-query faithfulness score');

        // The faked answer shares cache vocabulary (TTL, purge, Redis, cache)
        // with the cache-topic corpus chunks, so cache queries score > 0.
        // Aggregate is the mean of scored rows — must be > 0 if any row is > 0.
        $this->assertGreaterThan(0.0, $card['aggregate']['answer_faithfulness'], 'mean faithfulness > 0 (cache vocabulary overlap)');

        // Aggregate must equal the PHP mean of the per-query faithfulness values
        // so the aggregation path is exercised correctly too.
        $faithValues = array_map(fn ($r) => (float) $r['faithfulness'], $scored);
        $expectedMean = round(array_sum($faithValues) / count($faithValues), 4);
        $this->assertSame($expectedMean, $card['aggregate']['answer_faithfulness'], 'aggregate equals mean of per-query faithfulness');
    }
}
