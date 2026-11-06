<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use Illuminate\Support\Collection;

/**
 * W6 (qmd parity — `--min-score`) — drop results whose final score is below a
 * caller-chosen floor. The score is the one the result is ORDERED by: the
 * cross-encoder's `cross_encoder.blended` when that stage ran successfully
 * (it decides the final order and deliberately leaves `rerank_score`
 * untouched), else the reranker's `rerank_score`, else `vector_score`. Applied AFTER
 * ranking, so it never changes the order, only the tail; `null` = no floor
 * (every surface stays byte-identical when the option is not passed).
 *
 * This is a presentation filter for search surfaces. It is deliberately NOT
 * the refusal gate ({@see RetrievalGrounding}), which keeps its own thresholds.
 */
final class ResultScoreFloor
{
    /**
     * @param  Collection<int, array<string,mixed>>  $results
     * @return Collection<int, array<string,mixed>>
     */
    public static function apply(Collection $results, ?float $minScore): Collection
    {
        if ($minScore === null) {
            return $results;
        }

        return $results
            ->filter(static fn (array $r): bool => self::scoreOf($r) >= $minScore)
            ->values();
    }

    /** @param  array<string,mixed>  $result */
    public static function scoreOf(array $result): float
    {
        $cross = $result['cross_encoder'] ?? null;
        if (is_array($cross) && ($cross['status'] ?? null) === 'ok' && is_numeric($cross['blended'] ?? null)) {
            return (float) $cross['blended'];
        }

        return (float) ($result['rerank_score'] ?? $result['vector_score'] ?? 0.0);
    }
}
