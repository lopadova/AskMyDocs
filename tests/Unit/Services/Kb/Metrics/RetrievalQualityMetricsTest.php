<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Metrics;

use App\Services\Kb\Metrics\RetrievalQualityMetrics as M;
use PHPUnit\Framework\TestCase;

/**
 * v8.1 P2 — exact, deterministic checks on the retrieval-quality metrics
 * so any benchmark harness can trust the numbers.
 */
final class RetrievalQualityMetricsTest extends TestCase
{
    public function test_precision_at_k_counts_relevant_in_top_k(): void
    {
        // top-3 of [a,b,c,d]; relevant = {a,c,x} → a,c hit → 2/3.
        $this->assertEqualsWithDelta(
            2 / 3,
            M::precisionAtK(['a', 'b', 'c', 'd'], ['a', 'c', 'x'], 3),
            1e-9,
        );
    }

    public function test_precision_at_k_zero_when_no_hits_or_empty(): void
    {
        $this->assertSame(0.0, M::precisionAtK(['a', 'b'], ['x'], 2));
        $this->assertSame(0.0, M::precisionAtK([], ['a'], 3));
        $this->assertSame(0.0, M::precisionAtK(['a'], ['a'], 0));
    }

    public function test_reciprocal_rank_is_inverse_of_first_relevant_position(): void
    {
        $this->assertSame(1.0, M::reciprocalRank(['a', 'b', 'c'], ['a']));      // pos 1
        $this->assertEqualsWithDelta(1 / 2, M::reciprocalRank(['a', 'b', 'c'], ['b']), 1e-9); // pos 2
        $this->assertEqualsWithDelta(1 / 3, M::reciprocalRank(['a', 'b', 'c'], ['c']), 1e-9); // pos 3
        $this->assertSame(0.0, M::reciprocalRank(['a', 'b'], ['z']));            // none
    }

    public function test_ndcg_is_one_for_ideal_binary_order(): void
    {
        // Relevant docs already at the top → nDCG = 1.0.
        $gains = ['a' => 1, 'b' => 1, 'c' => 0, 'd' => 0];
        $this->assertEqualsWithDelta(1.0, M::ndcgAtK(['a', 'b', 'c', 'd'], $gains, 4), 1e-9);
    }

    public function test_ndcg_penalises_relevant_docs_ranked_lower(): void
    {
        $gains = ['a' => 1, 'b' => 1, 'c' => 0, 'd' => 0];
        $ideal = M::ndcgAtK(['a', 'b', 'c', 'd'], $gains, 4);
        $worse = M::ndcgAtK(['c', 'd', 'a', 'b'], $gains, 4);
        $this->assertGreaterThan($worse, $ideal);
        $this->assertGreaterThan(0.0, $worse);
        $this->assertLessThan(1.0, $worse);
    }

    public function test_ndcg_rewards_graded_relevance_order(): void
    {
        // A highly-relevant doc (grade 3) first beats it ranked last.
        $gains = ['hi' => 3, 'mid' => 2, 'lo' => 1];
        $best = M::ndcgAtK(['hi', 'mid', 'lo'], $gains, 3);
        $bad = M::ndcgAtK(['lo', 'mid', 'hi'], $gains, 3);
        $this->assertEqualsWithDelta(1.0, $best, 1e-9);
        $this->assertLessThan($best, $bad);
    }

    public function test_dcg_applies_log_discount_by_rank(): void
    {
        // Single relevant doc at rank 1 → gain (2^1-1)/log2(2) = 1/1 = 1.
        $this->assertEqualsWithDelta(1.0, M::dcg([1.0]), 1e-9);
        // Same doc at rank 2 → 1/log2(3) ≈ 0.6309.
        $this->assertEqualsWithDelta(1.0 / log(3, 2), M::dcg([0.0, 1.0]), 1e-9);
    }

    public function test_relevant_set_accepts_both_list_and_lookup_map(): void
    {
        $this->assertEqualsWithDelta(0.5, M::precisionAtK(['a', 'b'], ['a'], 2), 1e-9);
        $this->assertEqualsWithDelta(0.5, M::precisionAtK(['a', 'b'], ['a' => true], 2), 1e-9);
    }
}
