<?php

declare(strict_types=1);

namespace App\Services\Kb\Metrics;

/**
 * Pure information-retrieval quality metrics for tuning the RAG pipeline
 * (rerank weights, refusal thresholds, the @mention boost, diversification
 * cap) against a labelled relevance set.
 *
 * v8.1 P2 — this is the computational core. It takes a RANKED list of
 * retrieved document/chunk ids plus a ground-truth relevance map and
 * returns the standard offline metrics. A benchmark command + a labelled
 * fixture corpus (and optional wiring into padosoft/eval-harness for
 * trend dashboards) build on top of this and are tracked as a follow-up —
 * but the metrics themselves are exact, deterministic, and unit-tested so
 * any harness can trust the numbers.
 *
 * All methods are static + side-effect free.
 */
final class RetrievalQualityMetrics
{
    /**
     * Precision@k — fraction of the top-k retrieved ids that are relevant.
     *
     * @param  list<int|string>  $rankedIds   retrieved ids, best-first
     * @param  array<int|string, true>|list<int|string>  $relevantIds  the relevant set
     */
    public static function precisionAtK(array $rankedIds, array $relevantIds, int $k): float
    {
        if ($k <= 0) {
            return 0.0;
        }

        $relevant = self::asSet($relevantIds);
        $topK = array_slice($rankedIds, 0, $k);
        if ($topK === []) {
            return 0.0;
        }

        $hits = 0;
        foreach ($topK as $id) {
            if (isset($relevant[$id])) {
                $hits++;
            }
        }

        return $hits / count($topK);
    }

    /**
     * Mean Reciprocal Rank for a SINGLE query — 1 / (rank of the first
     * relevant id), or 0 when none of the retrieved ids are relevant.
     * Average across queries to get the dataset-level MRR.
     *
     * @param  list<int|string>  $rankedIds
     * @param  array<int|string, true>|list<int|string>  $relevantIds
     */
    public static function reciprocalRank(array $rankedIds, array $relevantIds): float
    {
        $relevant = self::asSet($relevantIds);

        foreach (array_values($rankedIds) as $index => $id) {
            if (isset($relevant[$id])) {
                return 1.0 / ($index + 1);
            }
        }

        return 0.0;
    }

    /**
     * nDCG@k with GRADED relevance. `gains` maps id → relevance grade
     * (0 = irrelevant, higher = more relevant; binary sets just use 1).
     * Uses the standard `2^rel - 1` gain and `log2(rank + 1)` discount,
     * normalised by the ideal DCG so the result is in [0, 1].
     *
     * @param  list<int|string>  $rankedIds          retrieved ids, best-first
     * @param  array<int|string, int|float>  $gains  id → relevance grade
     */
    public static function ndcgAtK(array $rankedIds, array $gains, int $k): float
    {
        if ($k <= 0) {
            return 0.0;
        }

        $dcg = self::dcg(
            array_map(static fn ($id) => (float) ($gains[$id] ?? 0.0), array_slice($rankedIds, 0, $k)),
        );

        // Ideal DCG: the same grades sorted descending (best possible order).
        $idealGrades = array_values($gains);
        rsort($idealGrades);
        $idcg = self::dcg(array_slice(array_map('floatval', $idealGrades), 0, $k));

        return $idcg > 0.0 ? $dcg / $idcg : 0.0;
    }

    /**
     * Discounted Cumulative Gain over an ordered list of relevance grades.
     *
     * @param  list<float>  $grades  relevance grades in ranked order
     */
    public static function dcg(array $grades): float
    {
        $dcg = 0.0;
        foreach (array_values($grades) as $index => $grade) {
            if ($grade <= 0.0) {
                continue;
            }
            // rank = index + 1 → discount log2(rank + 1) = log2(index + 2).
            $dcg += ((2 ** $grade) - 1) / log($index + 2, 2);
        }

        return $dcg;
    }

    /**
     * @param  array<int|string, true>|list<int|string>  $ids
     * @return array<int|string, true>
     */
    private static function asSet(array $ids): array
    {
        // Already a lookup map (id => true)?
        if ($ids !== [] && array_is_list($ids) === false) {
            return $ids;
        }

        $set = [];
        foreach ($ids as $id) {
            $set[$id] = true;
        }

        return $set;
    }
}
