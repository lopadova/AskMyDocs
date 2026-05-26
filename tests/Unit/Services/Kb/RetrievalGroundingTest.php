<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb;

use App\Services\Kb\Retrieval\RetrievalGrounding;
use Illuminate\Support\Collection;
use Orchestra\Testbench\TestCase;

/**
 * v8.1 — guards the refusal-gate correctness fix.
 *
 * Two regressions this locks down:
 *
 *  1. **Shape divergence (R13/R16).** `KbSearchService::search()` returns a
 *     Collection of ARRAYS, but the chat controllers used to read
 *     `$c->vector_score` (object syntax), which yields null → 0 on an array
 *     and silently disabled the gate in production. Every chat feature test
 *     mocked the search service with `(object)` chunks, so the suite was
 *     green while production was broken. These tests assert grounding works
 *     against the REAL array shape AND the legacy stdClass shape.
 *
 *  2. **Wrong ranking signal.** Gating on `vector_score` alone wrongly
 *     refused a chunk with weak vector similarity but a strong lexical /
 *     heading match (high `rerank_score`). The gate now grounds on
 *     rerank_score OR the vector floor.
 */
final class RetrievalGroundingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('kb.refusal.min_chunk_similarity', 0.45);
        $app['config']->set('kb.refusal.min_rerank_score', 0.25);
        $app['config']->set('kb.refusal.min_chunks_required', 1);
    }

    /** The exact array shape KbSearchService::search() + the Reranker emit. */
    private function arrayChunk(float $vector, ?float $rerank = null): array
    {
        $chunk = [
            'chunk_id' => 1,
            'chunk_text' => 'body',
            'heading_path' => 'h',
            'vector_score' => $vector,
            'document' => ['id' => 1, 'title' => 't', 'source_path' => 'p.md'],
        ];
        if ($rerank !== null) {
            $chunk['rerank_score'] = $rerank;
        }

        return $chunk;
    }

    public function test_array_chunk_above_vector_floor_is_grounded(): void
    {
        // Regression: the old `$c->vector_score` object read returned 0 here
        // and refused. data_get reads the array correctly.
        $this->assertTrue(RetrievalGrounding::isGrounded($this->arrayChunk(0.80), 0.25, 0.45));
    }

    public function test_array_chunk_below_both_floors_is_not_grounded(): void
    {
        $this->assertFalse(RetrievalGrounding::isGrounded($this->arrayChunk(0.10, 0.10), 0.25, 0.45));
    }

    public function test_strong_rerank_but_weak_vector_is_grounded(): void
    {
        // The review's exact concern: a low-vector, high-lexical/heading
        // match the reranker promoted must NOT be refused.
        $this->assertTrue(RetrievalGrounding::isGrounded($this->arrayChunk(0.10, 0.50), 0.25, 0.45));
    }

    public function test_object_fixture_shape_still_works(): void
    {
        // Legacy stdClass fixtures (and any future object-shaped caller)
        // must keep grounding via the same shape-agnostic read.
        $obj = (object) ['vector_score' => 0.80, 'rerank_score' => null];
        $this->assertTrue(RetrievalGrounding::isGrounded($obj, 0.25, 0.45));
    }

    public function test_mention_boost_alone_does_not_ground_an_irrelevant_chunk(): void
    {
        // P0.3 × P0.1 interaction: a mentioned doc's chunk whose rerank_score
        // (0.50) comes PURELY from the mention boost, with low intrinsic
        // signal (vector 0.10). Intrinsic = 0.50 - 0.50 = 0 < 0.25 and
        // vector < 0.45 → must NOT ground (the boost must not disable the
        // refusal gate).
        $chunk = ['vector_score' => 0.10, 'rerank_score' => 0.50, 'rerank_detail' => ['mention_boost' => 0.50]];
        $this->assertFalse(RetrievalGrounding::isGrounded($chunk, 0.25, 0.45));
    }

    public function test_mentioned_and_intrinsically_relevant_chunk_grounds(): void
    {
        // Mentioned AND genuinely relevant: rerank 0.80 includes the 0.50
        // boost → intrinsic 0.30 >= 0.25 → grounds.
        $chunk = ['vector_score' => 0.20, 'rerank_score' => 0.80, 'rerank_detail' => ['mention_boost' => 0.50]];
        $this->assertTrue(RetrievalGrounding::isGrounded($chunk, 0.25, 0.45));
    }

    public function test_should_refuse_when_all_chunks_weak(): void
    {
        $chunks = new Collection([
            $this->arrayChunk(0.10, 0.10),
            $this->arrayChunk(0.20, 0.15),
        ]);
        $this->assertTrue(RetrievalGrounding::shouldRefuse($chunks));
    }

    public function test_should_not_refuse_when_one_chunk_strong(): void
    {
        $chunks = new Collection([
            $this->arrayChunk(0.10, 0.10),
            $this->arrayChunk(0.80, 0.60),
        ]);
        $this->assertFalse(RetrievalGrounding::shouldRefuse($chunks));
    }

    public function test_empty_collection_refuses(): void
    {
        $this->assertTrue(RetrievalGrounding::shouldRefuse(new Collection()));
    }

    public function test_min_chunks_required_two_refuses_with_single_strong_chunk(): void
    {
        config()->set('kb.refusal.min_chunks_required', 2);
        $chunks = new Collection([
            $this->arrayChunk(0.80, 0.60),
            $this->arrayChunk(0.10, 0.10),
        ]);
        $this->assertTrue(RetrievalGrounding::shouldRefuse($chunks));
    }
}
