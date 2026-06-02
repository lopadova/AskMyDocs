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
 * v8.0/W3.1 — "Why-not-cited" — `messages.metadata.retrieval_runner_up`
 * is populated end-to-end through `/api/kb/chat`.
 *
 * The chat response now ships a sibling `meta.retrieval_runner_up`
 * array (the chunks that were CONSIDERED but did not survive into the
 * top-K primary set). The FE chat view uses this to render a
 * "Considered but not used" tab. R27 additive contract: the array
 * defaults to empty when no runner-up is populated, and the new keys
 * are added as siblings without renaming or sub-objectifying any
 * pre-W3.1 field.
 *
 * Test posture mirrors `KbChatResponseShapeTest`: KbSearchService is
 * stubbed with a controlled `SearchResult` (so the test runs against
 * SQLite, no pgvector requirement), AiManager returns a fake response.
 */
final class KbChatRunnerUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/kb/chat', KbChatController::class)->name('api.kb.chat');

        config()->set('kb.refusal.min_chunk_similarity', 0.45);
        config()->set('kb.refusal.min_chunks_required', 1);
        config()->set('chat-log.enabled', false);
        config()->set('kb.hybrid_search.enabled', false);
        config()->set('kb.reranking.enabled', true);
        config()->set('kb.graph.expansion_enabled', true);
        config()->set('kb.rejected.injection_enabled', true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_response_shape_includes_runner_up_array_with_reason_per_chunk(): void
    {
        $this->stubSearchWithRunnerUp(
            primaryScores: [0.92, 0.88, 0.85],
            runnerUpEntries: [
                ['chunk_id' => 11, 'title' => 'Doc 11', 'score' => 0.61],
                ['chunk_id' => 12, 'title' => 'Doc 12', 'score' => 0.57],
                ['chunk_id' => 13, 'title' => 'Doc 13', 'score' => 0.55],
            ],
        );
        $this->stubAiManager('A grounded answer.');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk();
        $runnerUp = $resp->json('meta.retrieval_runner_up');
        $this->assertIsArray($runnerUp, 'meta.retrieval_runner_up must be present even when empty');
        $this->assertCount(3, $runnerUp);
        $this->assertSame(3, $resp->json('meta.runner_up_count'));

        foreach ($runnerUp as $i => $row) {
            $this->assertArrayHasKey('chunk_id', $row, "runner_up[{$i}] missing chunk_id");
            $this->assertArrayHasKey('reason', $row, "runner_up[{$i}] must carry a demotion reason");
            $this->assertIsString($row['reason']);
            $this->assertArrayHasKey('document', $row);
            $this->assertArrayHasKey('vector_score', $row);
        }
    }

    public function test_runner_up_is_empty_array_when_no_candidates_demoted(): void
    {
        // R27 additive contract: legacy/empty payloads still ship the
        // `retrieval_runner_up` key as `[]` so FE clients can iterate
        // it uniformly without null-guards.
        $this->stubSearchWithRunnerUp(
            primaryScores: [0.92, 0.88, 0.85],
            runnerUpEntries: [],
        );
        $this->stubAiManager('A grounded answer.');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk();
        $this->assertSame([], $resp->json('meta.retrieval_runner_up'));
        $this->assertSame(0, $resp->json('meta.runner_up_count'));
    }

    public function test_r27_legacy_keys_still_present_alongside_new_runner_up(): void
    {
        // R27 — adding `retrieval_runner_up` MUST NOT remove or
        // rename any pre-W3.1 meta key. Pin the legacy shape via
        // assertJsonStructure to catch a regression where a future
        // refactor would silently drop a key.
        $this->stubSearchWithRunnerUp(
            primaryScores: [0.92, 0.88, 0.85],
            runnerUpEntries: [
                ['chunk_id' => 11, 'title' => 'Doc 11', 'score' => 0.61],
            ],
        );
        $this->stubAiManager('A grounded answer.');

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
                'search_strategy',
                'retrieval_stats',
                // v8.0/W3.1 new keys — additive.
                'retrieval_runner_up',
                'runner_up_count',
            ],
        ]);

        // Pre-W3.1 keys keep their established types.
        $this->assertIsInt($resp->json('meta.latency_ms'));
        $this->assertIsInt($resp->json('meta.primary_count'));
    }

    /**
     * @param  array<int, float>  $primaryScores
     * @param  array<int, array{chunk_id: int, title: string, score: float}>  $runnerUpEntries
     */
    private function stubSearchWithRunnerUp(array $primaryScores, array $runnerUpEntries): void
    {
        $primary = collect($primaryScores)->map(function (float $score, int $i) {
            return (object) [
                'id' => $i + 1,
                'knowledge_document_id' => $i + 1,
                'vector_score' => $score,
                'heading_path' => 'H',
                'chunk_text' => 'lorem',
                'document' => (object) [
                    'id' => $i + 1,
                    'title' => 'Doc '.($i + 1),
                    'source_path' => 'docs/d'.($i + 1).'.md',
                ],
            ];
        });

        $runnerUp = collect($runnerUpEntries)->map(fn (array $row): array => [
            'chunk_id' => $row['chunk_id'],
            'project_key' => 'test',
            'heading_path' => 'H',
            'chunk_text' => 'lorem ipsum runner-up preview',
            'vector_score' => $row['score'],
            'reason' => 'not_in_top_k',
            'document' => [
                'id' => $row['chunk_id'],
                'title' => $row['title'],
                'source_path' => 'docs/d'.$row['chunk_id'].'.md',
                'source_type' => 'markdown',
                'doc_id' => null,
                'slug' => null,
                'is_canonical' => false,
                'canonical_type' => null,
                'canonical_status' => null,
            ],
        ]);

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
                    'runner_up_count' => $runnerUp->count(),
                    'project_key' => 'test',
                    'filters_selected' => 0,
                    'retrieval_ms' => 30,
                    'search_strategy' => [
                        'semantic_enabled' => true,
                        'fts_enabled' => false,
                        'fusion_method' => 'rerank_weighted_sum',
                        'graph_expansion_enabled' => true,
                        'rejected_injection_enabled' => true,
                        'filters_applied' => 0,
                    ],
                    'retrieval_stats' => [
                        'candidates_pre_threshold' => 24,
                        'candidates_post_threshold' => $primary->count(),
                        'primary_count' => $primary->count(),
                        'expanded_count' => 0,
                        'rejected_count' => 0,
                        'min_score_used' => $primary->isEmpty() ? null : (float) $primaryScores[count($primaryScores) - 1],
                        'max_score_used' => $primary->isEmpty() ? null : (float) $primaryScores[0],
                    ],
                ],
                runnerUp: $runnerUp,
            )
        );
        $this->app->instance(KbSearchService::class, $search);
    }

    private function stubAiManager(string $content): void
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
}
