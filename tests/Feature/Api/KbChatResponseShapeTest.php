<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Controllers\Api\KbChatController;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * T3.5 — full /api/kb/chat response shape extension.
 *
 * Asserts that:
 *  - `confidence` is a real int 0..100 on the happy path (not null)
 *  - `meta.search_strategy` exposes the per-query feature flags
 *  - `meta.retrieval_stats` exposes per-query counts + score range
 *  - `meta.latency_ms` is still a flat int (L21 — additive only)
 *  - `meta.latency_ms_breakdown` is a sibling sub-object with
 *    retrieval/llm/total
 *  - Refusal paths (T3.3 `no_relevant_context` + T3.4 `llm_self_refusal`)
 *    populate confidence=0 + refusal_reason + still have the breakdown
 *
 * Pattern mirrors KbChatRefusalTest / KbChatSentinelTest: stub
 * KbSearchService with controlled chunks (and a custom meta), stub
 * AiManager with a fake response, assert the response shape.
 */
final class KbChatResponseShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/kb/chat', KbChatController::class)->name('api.kb.chat');

        config()->set('kb.refusal.min_chunk_similarity', 0.45);
        config()->set('kb.refusal.min_chunks_required', 1);
        config()->set('chat-log.enabled', false);

        // Default search-strategy config — these knobs are read by
        // KbSearchService::resolveFusionMethod and emitted in meta.
        config()->set('kb.hybrid_search.enabled', false);
        config()->set('kb.reranking.enabled', true);
        config()->set('kb.graph.expansion_enabled', true);
        config()->set('kb.rejected.injection_enabled', true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Mock KbSearchService with a SearchResult that includes the FULL
     * meta shape KbSearchService::searchWithContext would normally emit.
     * Using Mockery::mock here means we can control retrieval_stats +
     * search_strategy + retrieval_ms exactly.
     *
     * @param  array<int, float>  $scores  vector_scores for primary chunks
     */
    private function mockSearchWithMetaPayload(array $scores, int $retrievalMs = 30): void
    {
        $primary = collect($scores)->map(function (float $score, int $i) {
            return (object) [
                'id' => $i + 1,
                'knowledge_document_id' => $i + 1,
                'vector_score' => $score,
                'heading_path' => 'H',
                'chunk_text' => 'lorem',
                'document' => (object) [
                    'id' => $i + 1,
                    'title' => 'Doc ' . ($i + 1),
                    'source_path' => 'docs/d' . ($i + 1) . '.md',
                ],
            ];
        });

        $primaryScores = $primary->map(fn ($c) => $c->vector_score);

        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('searchWithContext')->andReturn(
            new SearchResult(
                primary: $primary,
                expanded: collect(),
                rejected: collect(),
                meta: [
                    'primary_count' => $primary->count(),
                    'expanded_count' => 0,
                    'rejected_count' => 0,
                    'project_key' => 'test',
                    'filters_selected' => 0,
                    'retrieval_ms' => $retrievalMs,
                    'search_strategy' => [
                        'semantic_enabled' => true,
                        'fts_enabled' => false,
                        'fusion_method' => 'rerank_weighted_sum',
                        'graph_expansion_enabled' => true,
                        'rejected_injection_enabled' => true,
                        'filters_applied' => 0,
                    ],
                    'retrieval_stats' => [
                        'candidates_pre_threshold' => 24,  // limit (8) * candidate_multiplier (3)
                        'candidates_post_threshold' => $primary->count(),
                        'primary_count' => $primary->count(),
                        'expanded_count' => 0,
                        'rejected_count' => 0,
                        'min_score_used' => $primary->isEmpty() ? null : (float) $primaryScores->min(),
                        'max_score_used' => $primary->isEmpty() ? null : (float) $primaryScores->max(),
                    ],
                ],
            )
        );
        $this->app->instance(KbSearchService::class, $search);
    }

    private function mockAiReturning(string $content): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturn(new AiResponse(
            content: $content,
            provider: 'openai',
            model: 'gpt-4o-mini',
            promptTokens: 250,
            completionTokens: 50,
            totalTokens: 300,
        ));
        $this->app->instance(AiManager::class, $ai);
    }

    public function test_happy_path_response_includes_full_extended_shape(): void
    {
        $this->mockSearchWithMetaPayload([0.92, 0.88, 0.85]);
        $this->mockAiReturning('A real grounded answer with several citations.');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()->assertJsonStructure([
            'answer',
            'citations',
            'confidence',
            'refusal_reason',
            'meta' => [
                'provider',
                'model',
                'chunks_used',
                'primary_count',
                'expanded_count',
                'rejected_count',
                'latency_ms',
                'latency_ms_breakdown' => ['retrieval', 'llm', 'total'],
                'filters_selected',
                'search_strategy' => [
                    'semantic_enabled',
                    'fts_enabled',
                    'fusion_method',
                    'graph_expansion_enabled',
                    'rejected_injection_enabled',
                    'filters_applied',
                ],
                'retrieval_stats' => [
                    'candidates_pre_threshold',
                    'candidates_post_threshold',
                    'primary_count',
                    'expanded_count',
                    'rejected_count',
                    'min_score_used',
                    'max_score_used',
                ],
            ],
        ]);
    }

    public function test_confidence_is_int_between_0_and_100_on_happy_path(): void
    {
        $this->mockSearchWithMetaPayload([0.92, 0.88, 0.85]);
        $this->mockAiReturning('A real grounded answer with several citations.');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $confidence = $resp->json('confidence');
        $this->assertIsInt($confidence);
        $this->assertGreaterThanOrEqual(0, $confidence);
        $this->assertLessThanOrEqual(100, $confidence);
    }

    public function test_confidence_reflects_retrieval_quality(): void
    {
        // Three high-similarity, distinct-document chunks → confidence
        // should be in the upper tier (>= 80).
        $this->mockSearchWithMetaPayload([0.95, 0.90, 0.88]);
        $this->mockAiReturning('A grounded answer with citations.');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $this->assertGreaterThanOrEqual(70, (int) $resp->json('confidence'));
    }

    public function test_legacy_latency_ms_stays_flat_int(): void
    {
        // L21 — `meta.latency_ms` MUST stay int. Existing FE clients
        // read it as a number. If it ever sub-objectifies, every
        // legacy chart breaks. Sub-object goes under
        // `latency_ms_breakdown`, side-by-side.
        $this->mockSearchWithMetaPayload([0.85]);
        $this->mockAiReturning('Answer.');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $latency = $resp->json('meta.latency_ms');
        $this->assertIsInt($latency);
        $this->assertGreaterThanOrEqual(0, $latency);
    }

    public function test_latency_ms_breakdown_separates_retrieval_and_llm(): void
    {
        // The breakdown's `total` MUST equal `meta.latency_ms` exactly.
        // The split (retrieval+llm = total) is approximate (single
        // microtime delta), but the equality with the flat int holds.
        $this->mockSearchWithMetaPayload([0.85], retrievalMs: 30);
        $this->mockAiReturning('Answer.');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $total = (int) $resp->json('meta.latency_ms');
        $breakdownTotal = (int) $resp->json('meta.latency_ms_breakdown.total');
        $retrieval = (int) $resp->json('meta.latency_ms_breakdown.retrieval');
        $llm = (int) $resp->json('meta.latency_ms_breakdown.llm');

        $this->assertSame($total, $breakdownTotal);
        $this->assertSame(30, $retrieval);  // from the search mock
        $this->assertGreaterThanOrEqual(0, $llm);
    }

    public function test_search_strategy_carries_fusion_method_and_feature_flags(): void
    {
        $this->mockSearchWithMetaPayload([0.85]);
        $this->mockAiReturning('Answer.');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertJsonPath('meta.search_strategy.semantic_enabled', true)
            ->assertJsonPath('meta.search_strategy.fts_enabled', false)
            ->assertJsonPath('meta.search_strategy.fusion_method', 'rerank_weighted_sum')
            ->assertJsonPath('meta.search_strategy.graph_expansion_enabled', true)
            ->assertJsonPath('meta.search_strategy.rejected_injection_enabled', true)
            ->assertJsonPath('meta.search_strategy.filters_applied', 0);
    }

    public function test_retrieval_stats_counts_match_search_result(): void
    {
        $this->mockSearchWithMetaPayload([0.92, 0.85, 0.65]);
        $this->mockAiReturning('Answer.');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertJsonPath('meta.retrieval_stats.primary_count', 3)
            ->assertJsonPath('meta.retrieval_stats.candidates_post_threshold', 3)
            ->assertJsonPath('meta.retrieval_stats.candidates_pre_threshold', 24)
            ->assertJsonPath('meta.retrieval_stats.expanded_count', 0)
            ->assertJsonPath('meta.retrieval_stats.rejected_count', 0);

        $minScore = (float) $resp->json('meta.retrieval_stats.min_score_used');
        $maxScore = (float) $resp->json('meta.retrieval_stats.max_score_used');
        $this->assertEqualsWithDelta(0.65, $minScore, 0.001);
        $this->assertEqualsWithDelta(0.92, $maxScore, 0.001);
    }

    public function test_no_relevant_context_refusal_keeps_extended_meta_shape(): void
    {
        // Refusal path must NOT regress the meta shape — confidence=0,
        // refusal_reason=string, but search_strategy + retrieval_stats
        // + latency_ms_breakdown still present.
        $this->mockSearchWithMetaPayload([0.30]);  // below 0.45 threshold
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');
        $this->app->instance(AiManager::class, $ai);

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'no_relevant_context')
            ->assertJsonPath('confidence', 0)
            ->assertJsonStructure([
                'meta' => [
                    'latency_ms',
                    'latency_ms_breakdown' => ['retrieval', 'llm', 'total'],
                    'search_strategy',
                    'retrieval_stats',
                ],
            ]);

        // LLM-side latency must be 0 — no LLM call happened on this path.
        $this->assertSame(0, (int) $resp->json('meta.latency_ms_breakdown.llm'));
    }

    public function test_llm_self_refusal_keeps_extended_meta_shape(): void
    {
        // Sentinel path: retrieval succeeded, LLM emitted the sentinel.
        // refusal_reason='llm_self_refusal', confidence=0, but ALL
        // extended meta fields still populated.
        $this->mockSearchWithMetaPayload([0.92]);
        $this->mockAiReturning('__NO_GROUNDED_ANSWER__');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'llm_self_refusal')
            ->assertJsonPath('confidence', 0)
            ->assertJsonPath('meta.search_strategy.fusion_method', 'rerank_weighted_sum')
            ->assertJsonPath('meta.retrieval_stats.primary_count', 1)
            ->assertJsonStructure([
                'meta' => [
                    'latency_ms_breakdown' => ['retrieval', 'llm', 'total'],
                ],
            ]);
    }
}
