<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Metrics;

use App\Services\Kb\Metrics\RetrievalQualityMetrics as M;
use Tests\TestCase;

/**
 * v8.1 P2 — exact, deterministic checks on the retrieval-quality metrics
 * so any benchmark harness can trust the numbers.
 *
 * v8.18/W2 — `reciprocalRank` + `ndcgAtK` now DELEGATE to padosoft/eval-harness
 * (via PackageMetricAdapter resolved from the container), so this suite extends
 * Tests\TestCase (booted app). The existing assertions stay intact; the
 * golden-equality tests below prove the delegation is behaviour-preserving.
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

    // --- v8.18/W2 delegation: golden-equality (frozen pre-delegation numbers) ---

    public function test_reciprocal_rank_delegates_with_identical_results(): void
    {
        self::assertSame(1.0, M::reciprocalRank(['a', 'b', 'c'], ['a']));
        self::assertEqualsWithDelta(1 / 2, M::reciprocalRank(['a', 'b', 'c'], ['b']), 1e-9);
        self::assertEqualsWithDelta(1 / 3, M::reciprocalRank(['a', 'b', 'c'], ['c']), 1e-9);
        self::assertSame(0.0, M::reciprocalRank(['a', 'b'], ['z']));
        // Lookup-map relevant set still accepted (back-compat).
        self::assertSame(1.0, M::reciprocalRank(['a', 'b'], ['a' => true]));
    }

    public function test_ndcg_delegates_with_identical_results(): void
    {
        $gains = ['a' => 1, 'b' => 1, 'c' => 0, 'd' => 0];
        self::assertEqualsWithDelta(1.0, M::ndcgAtK(['a', 'b', 'c', 'd'], $gains, 4), 1e-9);

        $worse = M::ndcgAtK(['c', 'd', 'a', 'b'], $gains, 4);
        self::assertGreaterThan(0.0, $worse);
        self::assertLessThan(1.0, $worse);

        $graded = ['hi' => 3, 'mid' => 2, 'lo' => 1];
        self::assertEqualsWithDelta(1.0, M::ndcgAtK(['hi', 'mid', 'lo'], $graded, 3), 1e-9);
        self::assertLessThan(
            M::ndcgAtK(['hi', 'mid', 'lo'], $graded, 3),
            M::ndcgAtK(['lo', 'mid', 'hi'], $graded, 3),
        );
        // k <= 0 guard preserved.
        self::assertSame(0.0, M::ndcgAtK(['a'], $gains, 0));
    }

    public function test_ndcg_graded_gain_math_matches_historical_2_pow_rel_minus_1(): void
    {
        // GRADED, NON-IDEAL ranking — this is the case that would silently break
        // if the delegated path forwarded raw grades (linear gain) instead of the
        // historical 2^rel-1 gain. Compute the historical nDCG via the in-app dcg()
        // (which applies 2^rel-1) and assert the delegated ndcgAtK() equals it.
        $gains = ['hi' => 3, 'mid' => 2, 'lo' => 1];
        $ranking = ['lo', 'hi', 'mid']; // deliberately not ideal

        // Historical: dcg() applies 2^grade-1 over the ranked grades; IDCG over the
        // ideal (descending) grade order.
        $dcg = M::dcg([1.0, 3.0, 2.0]);          // grades of lo, hi, mid in rank order
        $idcg = M::dcg([3.0, 2.0, 1.0]);         // ideal descending
        $expected = $dcg / $idcg;

        self::assertEqualsWithDelta($expected, M::ndcgAtK($ranking, $gains, 3), 1e-9);
        // Sanity: it must NOT equal the linear-gain value the package would yield
        // without the 2^rel-1 transform (3/2/1 raw) — proving the transform fired.
        $linearDcg = (1.0 / log(2, 2)) + (3.0 / log(3, 2)) + (2.0 / log(4, 2));
        $linearIdcg = (3.0 / log(2, 2)) + (2.0 / log(3, 2)) + (1.0 / log(4, 2));
        self::assertGreaterThan(1e-6, abs(($linearDcg / $linearIdcg) - $expected));
    }

    public function test_precision_at_k_is_kept_in_app_until_package_ships_it(): void
    {
        // The package has hit/recall/mrr/ndcg/answer-containment but no
        // precision@k. precisionAtK stays hand-rolled; revisit when the package
        // adds 'retrieval-precision-at-k'.
        self::assertFalse(class_exists('Padosoft\\EvalHarness\\Metrics\\RetrievalPrecisionAtKMetric'));
        self::assertEqualsWithDelta(2 / 3, M::precisionAtK(['a', 'b', 'c', 'd'], ['a', 'c', 'x'], 3), 1e-9);
    }

    public function test_empty_relevance_scores_zero_not_throws(): void
    {
        // Behaviour-preservation regression: the pre-delegation metrics returned
        // 0.0 for an unlabelled query (empty relevant set / empty gains). The
        // package REJECTS an empty expected_output with a MetricException, so the
        // adapter must short-circuit to 0.0 — otherwise a single benchmark query
        // with no `relevance` block aborts the whole BenchmarkRunner sweep.
        self::assertSame(0.0, M::reciprocalRank(['a', 'b', 'c'], []));
        self::assertSame(0.0, M::ndcgAtK(['a', 'b', 'c'], [], 3));
    }
}
