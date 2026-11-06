<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Retrieval\CrossEncoder;

use App\Services\Kb\Retrieval\CrossEncoder\CrossEncoderBlender;
use PHPUnit\Framework\TestCase;

/**
 * v8.43/W3 (ADR 0035 §D4) — the blend arithmetic, with strictly-monotonic
 * fixtures (R16) so each assertion would FAIL under the wrong rule.
 */
final class CrossEncoderBlenderTest extends TestCase
{
    private const TIERS = ['head_rank' => 3, 'mid_rank' => 10, 'head_weight' => 0.75, 'mid_weight' => 0.60, 'tail_weight' => 0.40];

    public function test_weight_tiers_are_inclusive_at_their_boundaries(): void
    {
        $this->assertSame(0.75, CrossEncoderBlender::weightFor(1, self::TIERS));
        $this->assertSame(0.75, CrossEncoderBlender::weightFor(3, self::TIERS));
        $this->assertSame(0.60, CrossEncoderBlender::weightFor(4, self::TIERS));
        $this->assertSame(0.60, CrossEncoderBlender::weightFor(10, self::TIERS));
        $this->assertSame(0.40, CrossEncoderBlender::weightFor(11, self::TIERS));
    }

    public function test_blend_is_the_documented_formula(): void
    {
        // rank 1: 0.75·0.8 + 0.25·(0.4·(1−0.5)) + 0.1 = 0.6 + 0.05 + 0.1
        $b = CrossEncoderBlender::blend(0.8, 0.4, 0.5, 1, 0.1, self::TIERS);

        $this->assertEqualsWithDelta(0.2, $b['cross_adjusted'], 1e-9);
        $this->assertEqualsWithDelta(0.75, $b['final'], 1e-9);
        $this->assertSame(0.75, $b['weight']);
    }

    public function test_status_penalty_stops_a_deprecated_doc_regaining_rank_through_text_similarity(): void
    {
        // Same heuristic position and the SAME perfect cross score; only the status differs.
        $accepted = CrossEncoderBlender::blend(0.5, 1.0, 0.0, 5, 0.0, self::TIERS)['final'];
        $deprecated = CrossEncoderBlender::blend(0.5, 1.0, 0.4, 5, 0.0, self::TIERS)['final'];

        $this->assertGreaterThan($deprecated, $accepted);
        $this->assertEqualsWithDelta(0.6 * 0.5 + 0.4 * 0.6, $deprecated, 1e-9);
    }

    public function test_mention_boost_is_added_after_the_blend_and_never_scaled(): void
    {
        $without = CrossEncoderBlender::blend(0.3, 0.3, 0.0, 12, 0.0, self::TIERS)['final'];
        $with = CrossEncoderBlender::blend(0.3, 0.3, 0.0, 12, 0.5, self::TIERS)['final'];

        $this->assertEqualsWithDelta(0.5, $with - $without, 1e-9);
    }

    public function test_a_clear_heuristic_winner_stays_first_even_when_the_model_disagrees(): void
    {
        // Heuristic gap is large (top 1.0 vs the rest ≤ 0.30). The model hates the
        // top (0.0) and loves the others (1.0). The head weight keeps the top #1.
        $h = CrossEncoderBlender::normalise([2.00, 0.80, 0.78, 0.75, 0.74, 0.73, 0.72, 0.71, 0.70, 0.69, 0.68, 0.67]);
        $finals = [];
        foreach ($h as $i => $hv) {
            $finals[] = CrossEncoderBlender::blend($hv, $i === 0 ? 0.0 : 1.0, 0.0, $i + 1, 0.0, self::TIERS)['final'];
        }

        $order = array_keys($finals);
        usort($order, fn (int $a, int $b): int => [$finals[$b], $a] <=> [$finals[$a], $b]);

        $this->assertSame(0, $order[0], 'the clearly-best heuristic hit is not buried by a dissenting model');
    }

    public function test_when_the_heuristic_cannot_separate_candidates_the_model_decides(): void
    {
        // A flat heuristic (all equal → all 1.0). The model prefers the LAST candidate.
        $h = CrossEncoderBlender::normalise([0.5, 0.5, 0.5, 0.5]);
        $this->assertSame([1.0, 1.0, 1.0, 1.0], $h);

        $cross = [0.1, 0.2, 0.3, 0.9];
        $finals = [];
        foreach ($h as $i => $hv) {
            $finals[] = CrossEncoderBlender::blend($hv, $cross[$i], 0.0, $i + 1, 0.0, self::TIERS)['final'];
        }

        $this->assertSame(3, array_search(max($finals), $finals, true), 'cross-encoder breaks the tie');
        $this->assertGreaterThan($finals[0], $finals[3]);
    }

    public function test_normalise_is_min_max_and_a_flat_or_empty_set_is_safe(): void
    {
        $this->assertEqualsWithDelta([0.0, 0.5, 1.0], CrossEncoderBlender::normalise([1.0, 2.0, 3.0]), 1e-9);
        $this->assertSame([1.0], CrossEncoderBlender::normalise([0.42]));
        $this->assertSame([], CrossEncoderBlender::normalise([]));
    }

    public function test_penalty_outside_0_1_is_clamped(): void
    {
        $b = CrossEncoderBlender::blend(1.0, 1.0, 5.0, 1, 0.0, self::TIERS);
        $this->assertSame(0.0, $b['cross_adjusted']);

        $c = CrossEncoderBlender::blend(1.0, 1.0, -3.0, 1, 0.0, self::TIERS);
        $this->assertSame(1.0, $c['cross_adjusted']);
    }
}
