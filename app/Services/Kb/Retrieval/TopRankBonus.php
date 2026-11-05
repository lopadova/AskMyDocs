<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

/**
 * W6 (qmd parity, ADR 0035 addendum) — a small, rank-based bonus added to the
 * fused RRF score of a chunk that the ORIGINAL query ranked at the very top of
 * any of its own lists (vector or full-text): +`first` for rank 1, +`podium`
 * for ranks 2–3.
 *
 * Why: RRF flattens differences at the head of a list — rank 1 and rank 3 of a
 * 60-constant RRF differ by ~3%, so an exact hit for the user's own words can
 * be overtaken by a chunk that several paraphrase lists ranked mid-table. qmd
 * protects the head with exactly this bonus; we adopt it, restricted to the
 * ORIGINAL query's lists so an expansion variant (model-written) can never earn
 * it. The bonus is monotonic per list and only reorders across lists.
 *
 * Default OFF (R43): with the flag false {@see self::for()} returns 0.0 and
 * every fusion is byte-identical to before.
 */
final class TopRankBonus
{
    public static function enabled(): bool
    {
        return (bool) config('kb.hybrid_search.top_rank_bonus.enabled', false);
    }

    /** Bonus for a chunk whose best 1-based rank in an original-query list is `$bestRank`. */
    public static function for(?int $bestRank): float
    {
        if ($bestRank === null || ! self::enabled()) {
            return 0.0;
        }
        if ($bestRank === 1) {
            return max(0.0, (float) config('kb.hybrid_search.top_rank_bonus.first', 0.05));
        }
        if ($bestRank <= 3) {
            return max(0.0, (float) config('kb.hybrid_search.top_rank_bonus.podium', 0.02));
        }

        return 0.0;
    }
}
