<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval\CrossEncoder;

use App\Services\Kb\Retrieval\CrossEncoder\CrossEncoderStage;
use App\Support\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;
use Padosoft\PiiRedactor\TokenStore\TokenStore;
use Tests\TestCase;

/**
 * v8.43/W3 (ADR 0035 §D4) — the stage end to end: ordering, the refusal
 * invariants, fallback, caching, PII, metering and tenant isolation.
 */
final class CrossEncoderStageTest extends TestCase
{
    private ?string $originalEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'kb.cross_rerank.enabled' => true,
            'kb.cross_rerank.driver' => 'fake',
            'kb.cross_rerank.candidates' => 30,
            'kb.cross_rerank.cache_ttl' => 3600,
            'kb.cross_rerank.profile' => 'self_hosted',
            'kb.cross_rerank.profiles.self_hosted' => [
                'endpoint' => 'https://rerank.internal.example/rerank',
                'model' => 'm-test',
                'api_key' => '',
                'allowed_hosts' => ['rerank.internal.example'],
                'allow_http' => false,
            ],
        ]);
        app(TenantContext::class)->set('tenant-a');
    }

    protected function tearDown(): void
    {
        // R16/R41 — restore global state this test mutates.
        if ($this->originalEnv !== null) {
            $this->app->detectEnvironment(fn () => $this->originalEnv);
        }
        parent::tearDown();
    }

    private function stage(): CrossEncoderStage
    {
        return $this->app->make(CrossEncoderStage::class);
    }

    /**
     * @param  list<array{text: string, h: float, penalty?: float, mention?: float}>  $rows  heuristic order, best first
     * @return Collection<int, array<string,mixed>>
     */
    private function candidates(array $rows): Collection
    {
        return collect($rows)->map(fn (array $r, int $i): array => [
            'chunk_id' => 100 + $i,
            'chunk_text' => $r['text'],
            'vector_score' => 0.5 + $i * 0.01,
            'rerank_score' => $r['h'],
            'rerank_detail' => ['mention_boost' => $r['mention'] ?? 0.0, 'canonical_penalty' => $r['penalty'] ?? 0.0],
            'document' => ['id' => 1 + $i],
        ]);
    }

    public function test_a_flat_heuristic_is_reordered_by_the_pair_reader_and_refusal_inputs_are_untouched(): void
    {
        // Equal heuristic scores; the FAKE model scores token overlap with the query.
        $ranked = $this->candidates([
            ['text' => 'unrelated shipping rates table', 'h' => 0.5],
            ['text' => 'refund window thirty days policy', 'h' => 0.5],
            ['text' => 'office parking rules', 'h' => 0.5],
        ]);

        [$out, $meta] = $this->stage()->apply('refund window policy', $ranked, 3, 'p');

        $this->assertSame('ok', $meta['status']);
        $this->assertSame(101, $out->first()['chunk_id'], 'the passage that answers the query moves to the top');

        // The refusal gate reads these two — they must be byte-identical to the input.
        foreach ($out as $chunk) {
            $in = $ranked->firstWhere('chunk_id', $chunk['chunk_id']);
            $this->assertSame($in['rerank_score'], $chunk['rerank_score']);
            $this->assertSame($in['vector_score'], $chunk['vector_score']);
            $this->assertSame($in['rerank_detail'], $chunk['rerank_detail']);
            $this->assertSame('ok', $chunk['cross_encoder']['status']);
            $this->assertSame('fake', $chunk['cross_encoder']['driver']);
        }
    }

    public function test_a_clear_heuristic_winner_keeps_the_head_against_a_dissenting_model(): void
    {
        $ranked = $this->candidates([
            ['text' => 'zzz nothing in common', 'h' => 2.0],
            ['text' => 'refund window policy text', 'h' => 0.8],
            ['text' => 'refund window policy again', 'h' => 0.78],
        ]);

        [$out] = $this->stage()->apply('refund window policy', $ranked, 3, 'p');

        $this->assertSame(100, $out->first()['chunk_id'], 'weight 0.75 protects a clear heuristic winner');
    }

    public function test_a_deprecated_document_does_not_regain_rank_through_text_similarity(): void
    {
        $ranked = $this->candidates([
            ['text' => 'refund window policy accepted wording', 'h' => 0.5],
            ['text' => 'refund window policy accepted wording', 'h' => 0.5, 'penalty' => 0.4],
        ]);

        [$out] = $this->stage()->apply('refund window policy', $ranked, 2, 'p');

        $this->assertSame([100, 101], $out->pluck('chunk_id')->all(), 'identical text, identical h — the penalised one stays behind');
    }

    public function test_an_at_mentioned_document_is_never_overtaken_by_an_unmentioned_one(): void
    {
        // The Reranker put the mentioned (but intrinsically irrelevant) doc first
        // by +0.5; the second doc is a perfect textual answer.
        $ranked = $this->candidates([
            ['text' => 'nothing relevant at all', 'h' => 0.95, 'mention' => 0.5],
            ['text' => 'refund window policy', 'h' => 0.50],
        ]);

        [$out] = $this->stage()->apply('refund window policy', $ranked, 2, 'p');

        $this->assertSame([100, 101], $out->pluck('chunk_id')->all(), 'the explicit @mention is a precedence, not a score');
    }

    public function test_the_head_weight_is_deliberately_sticky_with_a_wide_heuristic_margin(): void
    {
        // Min-max over the candidate set puts a 0.95 vs 0.50 pair at 1.0 vs 0.0;
        // with w=0.75 the model alone cannot overturn that (0.75 > 0.25). The
        // tiers are configurable (`kb.cross_rerank.tiers.*`) — this pins the default.
        $ranked = $this->candidates([
            ['text' => 'nothing relevant at all', 'h' => 0.95],
            ['text' => 'refund window policy', 'h' => 0.50],
        ]);

        [$out] = $this->stage()->apply('refund window policy', $ranked, 2, 'p');

        $this->assertSame(100, $out->first()['chunk_id']);

        config(['kb.cross_rerank.tiers.head_weight' => 0.10]);
        [$out] = $this->stage()->apply('refund window policy', $ranked, 2, 'p');
        $this->assertSame(101, $out->first()['chunk_id'], 'a lower head weight lets the model decide');
    }

    public function test_limit_slices_after_the_reorder(): void
    {
        $ranked = $this->candidates([
            ['text' => 'a', 'h' => 0.5], ['text' => 'b', 'h' => 0.5], ['text' => 'refund window policy', 'h' => 0.5],
        ]);

        [$out] = $this->stage()->apply('refund window policy', $ranked, 1, 'p');

        $this->assertCount(1, $out);
        $this->assertSame(102, $out->first()['chunk_id'], 'the best passage survives the cut even from heuristic position 3');
    }

    public function test_an_empty_candidate_set_is_skipped_without_a_driver_call(): void
    {
        Http::fake();
        config(['kb.cross_rerank.driver' => 'http']);

        [$out, $meta] = $this->stage()->apply('q', collect(), 5, 'p');

        $this->assertTrue($out->isEmpty());
        $this->assertSame('skipped', $meta['status']);
        Http::assertNothingSent();
    }

    // ── failure → heuristic order, loudly ────────────────────────────────

    public function test_a_failing_endpoint_falls_back_to_the_heuristic_order_and_says_so(): void
    {
        Log::spy();
        config(['kb.cross_rerank.driver' => 'http']);
        Http::fake(['*' => Http::response('boom', 503)]);
        $ranked = $this->candidates([['text' => 'x', 'h' => 0.9], ['text' => 'y', 'h' => 0.1]]);

        [$out, $meta] = $this->stage()->apply('q', $ranked, 2, 'p');

        $this->assertSame([100, 101], $out->pluck('chunk_id')->all());
        $this->assertSame(['status' => 'unavailable', 'reason' => 'http_503'], $meta);
        $this->assertArrayNotHasKey('cross_encoder', $out->first(), 'no half-applied scores');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => $m === 'kb.cross_rerank.unavailable')->once();
    }

    public function test_an_unknown_driver_and_an_unknown_profile_degrade_not_throw(): void
    {
        $ranked = $this->candidates([['text' => 'x', 'h' => 0.9]]);

        config(['kb.cross_rerank.driver' => 'nonsense']);
        $this->assertSame('unknown_driver', $this->stage()->apply('q', $ranked, 1, 'p')[1]['reason']);

        config(['kb.cross_rerank.driver' => 'http', 'kb.cross_rerank.profile' => 'cloud-nine']);
        $this->assertSame('unknown_profile', $this->stage()->apply('q', $ranked, 1, 'p')[1]['reason']);
    }

    public function test_the_fake_driver_is_refused_in_production(): void
    {
        $this->originalEnv = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');

        $meta = $this->stage()->apply('q', $this->candidates([['text' => 'x', 'h' => 0.9]]), 1, 'p')[1];

        $this->assertSame(['status' => 'unavailable', 'reason' => 'fake_in_production'], $meta);
    }

    public function test_a_host_outside_the_allow_list_never_receives_the_query(): void
    {
        config(['kb.cross_rerank.driver' => 'http', 'kb.cross_rerank.profiles.self_hosted.endpoint' => 'https://evil.example/rerank']);
        Http::fake();

        $meta = $this->stage()->apply('secret question', $this->candidates([['text' => 'x', 'h' => 0.9]]), 1, 'p')[1];

        $this->assertSame('endpoint_not_allowed', $meta['reason']);
        Http::assertNothingSent();
    }

    // ── cache, metering, PII, tenancy ────────────────────────────────────

    private function httpScores(): void
    {
        config(['kb.cross_rerank.driver' => 'http']);
        Http::fake(['rerank.internal.example/*' => fn (Request $r) => Http::response(['results' => collect($r['documents'])->map(
            fn ($d, $i) => ['index' => $i, 'relevance_score' => 0.5],
        )->all()])]);
    }

    public function test_scores_are_cached_so_a_repeat_makes_no_second_call(): void
    {
        $this->httpScores();
        $ranked = $this->candidates([['text' => 'alpha', 'h' => 0.9], ['text' => 'beta', 'h' => 0.1]]);

        $this->stage()->apply('same query', $ranked, 2, 'p');
        $this->stage()->apply('same query', $ranked, 2, 'p');

        Http::assertSentCount(1);
    }

    public function test_only_the_uncached_passages_are_sent_on_a_partial_hit(): void
    {
        $this->httpScores();

        $this->stage()->apply('q', $this->candidates([['text' => 'alpha', 'h' => 0.9]]), 1, 'p');
        $this->stage()->apply('q', $this->candidates([['text' => 'alpha', 'h' => 0.9], ['text' => 'beta', 'h' => 0.1]]), 2, 'p');

        $sent = Http::recorded();
        $this->assertCount(2, $sent);
        $this->assertSame(['beta'], $sent[1][0]['documents'], 'alpha was cached; only beta travelled');
    }

    public function test_the_cache_is_per_tenant(): void
    {
        $this->httpScores();
        $ranked = $this->candidates([['text' => 'alpha', 'h' => 0.9]]);

        app(TenantContext::class)->set('tenant-a');
        $this->stage()->apply('q', $ranked, 1, 'p');
        app(TenantContext::class)->set('tenant-b');
        $this->stage()->apply('q', $ranked, 1, 'p');

        Http::assertSentCount(2);
    }

    public function test_a_model_swap_does_not_reuse_old_scores(): void
    {
        $this->httpScores();
        $ranked = $this->candidates([['text' => 'alpha', 'h' => 0.9]]);

        $this->stage()->apply('q', $ranked, 1, 'p');
        config(['kb.cross_rerank.profiles.self_hosted.model' => 'm-test-v2']);
        $this->stage()->apply('q', $ranked, 1, 'p');

        Http::assertSentCount(2);
    }

    public function test_cache_disabled_with_ttl_zero_always_calls(): void
    {
        $this->httpScores();
        config(['kb.cross_rerank.cache_ttl' => 0]);
        $ranked = $this->candidates([['text' => 'alpha', 'h' => 0.9]]);

        $this->stage()->apply('q', $ranked, 1, 'p');
        $this->stage()->apply('q', $ranked, 1, 'p');

        Http::assertSentCount(2);
    }

    public function test_every_uncached_call_is_metered_under_the_cross_rerank_purpose_and_a_hit_is_not(): void
    {
        $recorded = [];
        $recorder = new class($recorded) implements UsageRecorder
        {
            /** @param  list<AiCallEnvelope>  $sink */
            public function __construct(public array &$sink) {}

            public function record(AiCallEnvelope $envelope): void
            {
                $this->sink[] = $envelope;
            }
        };
        $this->app->instance(UsageRecorder::class, $recorder);
        $this->httpScores();
        $ranked = $this->candidates([['text' => 'alpha', 'h' => 0.9], ['text' => 'beta', 'h' => 0.1]]);

        $this->stage()->apply('q', $ranked, 2, 'p');
        $this->assertCount(1, $recorded);
        $this->assertSame('cross_rerank', $recorded[0]->purposeTag);
        $this->assertSame('tenant-a', $recorded[0]->tenantId);
        $this->assertSame(2, $recorded[0]->metadata['passages']);

        $this->stage()->apply('q', $ranked, 2, 'p');
        $this->assertCount(1, $recorded, 'a full cache hit makes no call and records nothing');
    }

    public function test_a_metering_failure_never_breaks_the_search(): void
    {
        $this->app->instance(UsageRecorder::class, new class implements UsageRecorder
        {
            public function record(AiCallEnvelope $envelope): void
            {
                throw new \RuntimeException('ledger down');
            }
        });
        $this->httpScores();

        [$out, $meta] = $this->stage()->apply('q', $this->candidates([['text' => 'alpha', 'h' => 0.9]]), 1, 'p');

        $this->assertSame('ok', $meta['status']);
        $this->assertCount(1, $out);
    }

    public function test_pii_in_the_query_is_masked_before_it_leaves_the_process(): void
    {
        $email = 'mario.rossi@example.com';
        config([
            'pii-redactor.enabled' => true,
            'pii-redactor.salt' => 'cross-test-salt',
            'pii-redactor.token_store.driver' => 'memory',
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.redact_inline_ingest' => true,
            'kb.pii_redactor.ingest_strategy' => 'mask',
        ]);
        foreach ([RedactorEngine::class, RedactionStrategyFactory::class, RedactionStrategy::class, TokenStore::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
        $this->httpScores();

        $this->stage()->apply('invoices of '.$email, $this->candidates([['text' => 'alpha', 'h' => 0.9]]), 1, 'p');

        Http::assertSent(fn (Request $r): bool => ! str_contains((string) $r['query'], $email) && str_contains((string) $r['query'], 'invoices of'));
    }

    public function test_the_intent_travels_with_the_query_but_never_alone(): void
    {
        $this->httpScores();

        $this->stage()->apply('performance', $this->candidates([['text' => 'alpha', 'h' => 0.9]]), 1, 'p', 'web page load times');

        Http::assertSent(fn (Request $r): bool => $r['query'] === "web page load times\n\nperformance");
    }
}
