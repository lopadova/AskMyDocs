<?php

declare(strict_types=1);

namespace App\Services\Kb\Grounding;

use Illuminate\Support\Collection;

/**
 * T3.2 — Composite confidence score for an LLM answer.
 *
 * Pure arithmetic — no I/O, no DB, no AI. Takes the retrieval+answer
 * shape and produces an integer 0..100 that rolls four signals into one
 * number the FE can render as a single badge:
 *
 *   confidence = 100 * (
 *       0.40 * mean_top_k_similarity +
 *       0.20 * threshold_margin +
 *       0.20 * chunk_diversity +
 *       0.20 * citation_density
 *   )
 *
 * Why these four:
 *  - **mean_top_k_similarity** (40%) is the strongest signal — high vector
 *    scores mean the retrieved chunks actually match the query. Weighted
 *    most heavily because everything else falls apart if retrieval fails.
 *  - **threshold_margin** (20%) — how far the WORST used chunk is above the
 *    refusal threshold. A weak chunk that just-barely-passed pulls the
 *    score down even when the top chunk is excellent.
 *  - **chunk_diversity** (20%) — distinct documents / total chunks. An
 *    answer drawn from one document is more fragile than one cross-cited
 *    across multiple sources.
 *  - **citation_density** (20%) — citations / answer-segment. Long answers
 *    with one citation are guessy; short answers with several citations
 *    are tightly grounded.
 *
 * Boundary cases:
 *  - Zero primary chunks → 0 (caller should already be on the refusal
 *    short-circuit; this is defense-in-depth).
 *  - Threshold === min_used_score → margin=0, only diversity+density
 *    contribute → confidence is at most 40 in this degenerate case.
 *  - Zero answer words → density treated as 1 (we don't penalize the
 *    refusal path for having "no answer").
 *
 * Producer-side clamping: result is always int in [0, 100]. The schema
 * column accepts the same range (T3.1) without a CHECK constraint —
 * this method is the load-bearing invariant.
 */
final class ConfidenceCalculator
{
    private const WEIGHT_MEAN_SIM = 0.40;
    private const WEIGHT_MARGIN = 0.20;
    private const WEIGHT_DIVERSITY = 0.20;
    private const WEIGHT_DENSITY = 0.20;

    /**
     * @param  Collection<int, object|array{vector_score: float, knowledge_document_id: int}>  $primaryChunks
     *   Ordered ranked-and-thresholded primary chunks. Each entry must
     *   expose `vector_score` (0..1) and `knowledge_document_id` (for
     *   diversity counting). Object or array — both shapes are accepted
     *   so callers don't need to materialize a DTO just for the score.
     * @param  float  $minThreshold
     *   The min similarity that the chunks had to pass (KbSearchService
     *   threshold, typically 0.45). Used to compute the safety margin.
     * @param  int  $answerWords
     *   Word count of the LLM answer. Used to compute citation density.
     *   Pass 0 on the refusal path — density is then forced to 1 so the
     *   refusal score is not penalised for having no answer to cite.
     * @param  int  $citationsCount
     *   How many of the primary chunks ended up cited in the answer.
     *   Caller does this counting (citation extraction lives in the
     *   controller). Should be ≤ count($primaryChunks) but isn't enforced.
     * @return int  0..100, inclusive.
     */
    public function compute(
        Collection $primaryChunks,
        float $minThreshold,
        int $answerWords,
        int $citationsCount,
    ): int {
        if ($primaryChunks->isEmpty()) {
            return 0;
        }

        $scores = $primaryChunks->map(fn ($c) => (float) $this->fieldOf($c, 'vector_score'));
        $docIds = $primaryChunks->map(fn ($c) => (int) $this->fieldOf($c, 'knowledge_document_id'));

        $meanSim = $this->clamp01($scores->avg() ?? 0.0);

        $minUsed = $scores->min() ?? 0.0;
        $margin = $this->thresholdMargin($minUsed, $minThreshold);

        $diversity = $this->clamp01($docIds->unique()->count() / $primaryChunks->count());

        $density = $this->citationDensity($citationsCount, $answerWords);

        $score = 100 * (
            self::WEIGHT_MEAN_SIM * $meanSim
            + self::WEIGHT_MARGIN * $margin
            + self::WEIGHT_DIVERSITY * $diversity
            + self::WEIGHT_DENSITY * $density
        );

        return (int) round(max(0.0, min(100.0, $score)));
    }

    /**
     * (min_used - threshold) / (1 - threshold), clamped to [0,1].
     *
     * Edge case: if the threshold is already at or above 1.0 (which
     * shouldn't happen — the caller would've refused everything), we'd
     * divide by ≤ 0. Guard returns 0 in that pathological case.
     */
    private function thresholdMargin(float $minUsed, float $threshold): float
    {
        $denominator = 1.0 - $threshold;
        if ($denominator <= 0.0) {
            return 0.0;
        }

        $raw = ($minUsed - $threshold) / $denominator;

        return $this->clamp01($raw);
    }

    /**
     * citations / answer_segment_count, capped at 1.
     *
     * One "answer segment" ≈ 100 words. Short answers (≤100 words) need
     * one citation to score full density; longer answers need
     * proportionally more.
     *
     * Refusal path (answerWords === 0) returns 1 — refusal payloads
     * don't pretend to cite anything, so the density signal should be
     * neutral for them, not punitive.
     */
    private function citationDensity(int $citations, int $answerWords): float
    {
        if ($answerWords <= 0) {
            return 1.0;
        }

        $segments = max(1, (int) round($answerWords / 100));

        return $this->clamp01($citations / $segments);
    }

    private function clamp01(float $x): float
    {
        return max(0.0, min(1.0, $x));
    }

    /**
     * Read a field from an object or array uniformly. Lets callers pass
     * Eloquent rows, stdClass results from DB::table(), arrays from
     * tests, or plain DTOs without a wrapping converter.
     */
    private function fieldOf(object|array $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? 0;
        }

        return $row->{$key} ?? 0;
    }
}
