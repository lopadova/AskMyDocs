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
     * KEPT IN-APP (v8.18/W2): `padosoft/eval-harness` v1.3 ships
     * hit/recall/mrr/ndcg/answer-containment but **no** precision@k, so this
     * stays hand-rolled. Delegate it once the package adds
     * `retrieval-precision-at-k` (recommended upstream — see the metrics LESSON).
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
        // v8.18/W2 — delegates the MRR formula to padosoft/eval-harness
        // `retrieval-mrr` (single source of truth). Signature unchanged; the
        // result is golden-equal (1e-9) to the historical hand-rolled value.
        return self::adapter()->scoreMrr(
            array_values($rankedIds),
            self::relevantList($relevantIds),
        );
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
            return 0.0; // preserve the historical guard.
        }

        // v8.18/W2 — delegates the nDCG@k formula (2^rel-1 gain + log2 discount,
        // normalised by the ideal DCG) to padosoft/eval-harness
        // `retrieval-ndcg-at-k`. Golden-equal (1e-9) to the old hand-rolled value;
        // `dcg()` below stays in-app (the package exposes no standalone DCG).
        return self::adapter()->scoreNdcg(array_values($rankedIds), $gains, $k);
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
     * Answer-containment@k — 1.0 when the expected answer string is found within
     * the top-k retrieved chunk TEXTS, else 0.0. ADDITIVE capability (v8.18/W2):
     * delegates to padosoft/eval-harness `answer-containment-at-k`. AskMyDocs had
     * no answer@k metric before; no existing caller changes.
     *
     * @param  list<array{id:int|string, text:string}>  $rankedChunks  best-first
     */
    public static function answerContainmentAtK(array $rankedChunks, string $expectedAnswer, int $k): float
    {
        // Consistency with precisionAtK()/ndcgAtK(): a non-positive window has no
        // top-k, so score 0.0 without emitting an invalid metadata.k to the
        // package. An empty expected answer has nothing to contain → 0.0 too.
        if ($k <= 0 || trim($expectedAnswer) === '') {
            return 0.0;
        }

        return self::adapter()->answerContainment($rankedChunks, $expectedAnswer, $k);
    }

    /**
     * Resolve the adapter from the container per call. NOT cached in a static
     * property on purpose: the adapter holds the active {@see ConfigRepository},
     * and PHPUnit reuses one process across tests — a static cache would pin the
     * first test's config repo and leak it into later tests. The resolution is a
     * cheap auto-wire of a single-dependency class, negligible even in a full
     * benchmark sweep.
     */
    private static function adapter(): PackageMetricAdapter
    {
        return app(PackageMetricAdapter::class);
    }

    /**
     * Normalise the app's relevant set (a flat list OR an id=>true lookup map)
     * into the flat string-id list the package's expected_output contract wants.
     *
     * @param  array<int|string, true>|list<int|string>  $relevantIds
     * @return list<string>
     */
    private static function relevantList(array $relevantIds): array
    {
        return array_map('strval', array_keys(self::asSet($relevantIds)));
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
