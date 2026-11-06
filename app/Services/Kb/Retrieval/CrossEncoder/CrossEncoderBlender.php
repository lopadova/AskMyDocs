<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\CrossEncoder;

/**
 * v8.43/W3 (ADR 0035 §D4) — the position-aware blend, as pure arithmetic.
 *
 *   h  = the heuristic score, min-max normalised over the candidate set with
 *        the @mention boost removed (a flat set maps to 1.0)
 *   c' = the cross-encoder score × (1 − status penalty) — a deprecated or
 *        superseded document cannot regain rank through text similarity
 *   w  = 0.75 for heuristic ranks ≤ 3, 0.60 for ≤ 10, 0.40 beyond (tiers are
 *        configurable) — the head of the heuristic order is protected: an exact
 *        canonical match the heuristic put first cannot be buried by a model
 *        that disagrees
 *   final = w·h + (1 − w)·c' + mention_boost
 *
 * The mention bonus is added AFTER the blend so an explicit user @mention is
 * never overridden; the stage passes a flat 1.0 for a mentioned document
 * (≥ the maximum of the blended terms), i.e. a precedence rather than a score.
 * Unlike a rank-fusion blend (`1/rank`), `h` is a calibrated
 * score, so a large heuristic margin is not flattened.
 */
final class CrossEncoderBlender
{
    /**
     * @param  array{head_rank: int, mid_rank: int, head_weight: float, mid_weight: float, tail_weight: float}  $tiers
     */
    public static function weightFor(int $rank, array $tiers): float
    {
        return match (true) {
            $rank <= $tiers['head_rank'] => $tiers['head_weight'],
            $rank <= $tiers['mid_rank'] => $tiers['mid_weight'],
            default => $tiers['tail_weight'],
        };
    }

    /**
     * @param  array{head_rank: int, mid_rank: int, head_weight: float, mid_weight: float, tail_weight: float}  $tiers
     * @return array{weight: float, final: float, cross_adjusted: float}
     */
    public static function blend(
        float $heuristicNormalised,
        float $crossScore,
        float $statusPenalty,
        int $heuristicRank,
        float $mentionBoost,
        array $tiers,
    ): array {
        $weight = self::weightFor($heuristicRank, $tiers);
        $adjusted = $crossScore * (1.0 - min(1.0, max(0.0, $statusPenalty)));

        return [
            'weight' => $weight,
            'final' => ($weight * $heuristicNormalised) + ((1.0 - $weight) * $adjusted) + $mentionBoost,
            'cross_adjusted' => $adjusted,
        ];
    }

    /**
     * Min-max normalise; a flat (or single-element) set maps to 1.0 so it
     * neither inflates nor zeroes the heuristic term.
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function normalise(array $values): array
    {
        if ($values === []) {
            return [];
        }
        $min = min($values);
        $range = max($values) - $min;

        return array_map(static fn (float $v): float => $range > 0.0 ? ($v - $min) / $range : 1.0, $values);
    }
}
