<?php

declare(strict_types=1);

namespace App\Services\Kb\Metrics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\AnswerContainmentAtKMetric;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Metrics\RetrievalMrrMetric;
use Padosoft\EvalHarness\Metrics\RetrievalNdcgAtKMetric;

/**
 * v8.18/W2 — anti-corruption layer between AskMyDocs's in-app ranked-chunk
 * results and the `padosoft/eval-harness` retrieval-metric math. The ONLY file
 * in the RETRIEVAL-METRICS layer (`app/Services/Kb/Metrics/`) that imports a
 * `Padosoft\EvalHarness\…` symbol — so a package API change to the retrieval
 * metrics touches here and nowhere else in this layer. (The separate eval
 * subsystem under `app/Eval/*` has its own, independent eval-harness imports.)
 * No HTTP, no DB: the delegated retrieval metrics are pure.
 *
 * It converts the app's ranked-id list + relevance data into the package's
 * `actualOutput` JSON + {@see DatasetSample}, runs the package metric, and
 * returns the raw `float` score. `k` is passed per-sample as `metadata.k`; when
 * omitted, the package metric applies its OWN built-in default k (the host does
 * not publish an `eval-harness.metrics.retrieval` config block).
 *
 * @internal Use through {@see RetrievalQualityMetrics}, not directly from callers.
 */
final class PackageMetricAdapter
{
    private const SAMPLE_ID = 'askmydocs-inproc';

    public function __construct(private readonly ConfigRepository $configRepo) {}

    // ---------------------------------------------------------------------
    // High-level resolvers — callers (RetrievalQualityMetrics) use THESE so
    // they never name a package metric class; this adapter constructs each
    // metric directly with the INJECTED ConfigRepository ($this->configRepo),
    // so it stays usable in pure (non-Laravel) contexts with no container.
    // ---------------------------------------------------------------------

    /**
     * MRR over a ranked-id list + a flat relevant set.
     *
     * @param  list<int|string>  $rankedIds
     * @param  list<int|string>  $relevantIds
     */
    public function scoreMrr(array $rankedIds, array $relevantIds, ?int $k = null): float
    {
        return $this->scoreRanked(new RetrievalMrrMetric($this->configRepo), $rankedIds, $relevantIds, $k);
    }

    /**
     * nDCG@k over a ranked-id list + a graded {id:grade} map.
     *
     * @param  list<int|string>  $rankedIds
     * @param  array<int|string, int|float>  $gains
     */
    public function scoreNdcg(array $rankedIds, array $gains, ?int $k = null): float
    {
        return $this->scoreRankedWithGains(new RetrievalNdcgAtKMetric($this->configRepo), $rankedIds, $gains, $k);
    }

    /**
     * Answer-containment@k over ranked chunk texts + an expected answer string.
     *
     * @param  list<array{id:int|string, text:string}>  $rankedChunks  best-first
     */
    public function answerContainment(array $rankedChunks, string $expectedAnswer, ?int $k = null): float
    {
        // Guard at the adapter boundary too (the method is public): a non-positive
        // window has no top-k, and an empty/whitespace expected answer has nothing
        // to contain → 0.0, never the package's degenerate "empty string is
        // contained" result. Matches RetrievalQualityMetrics::answerContainmentAtK().
        // Trim once and forward the trimmed value so the guard + the scoring input
        // stay consistent (a padded " answer " isn't handed to the metric padded).
        $expectedAnswer = trim($expectedAnswer);
        if (($k !== null && $k <= 0) || $expectedAnswer === '') {
            return 0.0;
        }

        return $this->scoreAnswerContainment(new AnswerContainmentAtKMetric($this->configRepo), $rankedChunks, $expectedAnswer, $k);
    }

    /**
     * Run a metric whose expected_output is a flat relevant-id list
     * (hit@k / recall@k / mrr).
     *
     * @param  list<int|string>  $rankedIds   best-first
     * @param  list<int|string>  $relevantIds
     */
    public function scoreRanked(Metric $metric, array $rankedIds, array $relevantIds, ?int $k = null): float
    {
        // Consistency with the app-level k<=0 → 0.0 convention; also avoids
        // propagating an invalid cutoff into the package metadata.
        if ($k !== null && $k <= 0) {
            return 0.0;
        }

        // Behaviour-preservation: the old hand-rolled metrics returned 0.0 when
        // the relevant set was empty (nothing is relevant → MRR/hit/recall = 0).
        // The package instead REJECTS an empty expected_output with a
        // MetricException, so short-circuit here — a benchmark query with no
        // labelled relevance must score 0.0, not abort the whole run.
        if ($relevantIds === []) {
            return 0.0;
        }

        $sample = new DatasetSample(
            id: self::SAMPLE_ID,
            input: [],
            expectedOutput: array_values(array_map('strval', $relevantIds)),
            metadata: $k !== null ? ['k' => $k] : [],
        );

        return $metric->score($sample, $this->actualOutput($rankedIds))->score;
    }

    /**
     * Run a metric whose expected_output is a graded {id:gain} map (nDCG@k).
     *
     * IMPORTANT — gain transform happens HERE: the caller passes relevance
     * GRADES (0,1,2,3,…), but `RetrievalNdcgAtKMetric` treats expected_output as
     * the ALREADY-COMPUTED gains (it applies only the log discount, no exponent).
     * AskMyDocs's historical nDCG used the standard `2^grade - 1` gain (see
     * {@see RetrievalQualityMetrics::dcg()}), so we apply that transform here —
     * clamping non-positive grades to 0.0 to match the old `dcg()` skip rule —
     * before delegating. This keeps the delegated nDCG byte-equal to the
     * pre-delegation numbers for GRADED relevance, not just binary.
     *
     * @param  list<int|string>  $rankedIds
     * @param  array<int|string, int|float>  $gains  id → relevance GRADE
     */
    public function scoreRankedWithGains(Metric $metric, array $rankedIds, array $gains, ?int $k = null): float
    {
        // Consistency with ndcgAtK()'s k<=0 → 0.0; also avoids passing an invalid
        // cutoff to the package metadata.
        if ($k !== null && $k <= 0) {
            return 0.0;
        }

        // Same behaviour-preservation as scoreRanked(): an empty gains map meant
        // IDCG = 0 → nDCG 0.0 in the old code; the package would throw on the
        // empty expected_output instead. Short-circuit to 0.0.
        if ($gains === []) {
            return 0.0;
        }

        $graded = [];
        foreach ($gains as $id => $grade) {
            $g = (float) $grade;
            // 2^grade - 1 (standard nDCG gain); non-positive grade → 0.0 gain.
            $graded[(string) $id] = $g > 0.0 ? (2 ** $g) - 1.0 : 0.0;
        }

        $sample = new DatasetSample(
            id: self::SAMPLE_ID,
            input: [],
            expectedOutput: $graded,
            metadata: $k !== null ? ['k' => $k] : [],
        );

        return $metric->score($sample, $this->actualOutput($rankedIds))->score;
    }

    /**
     * Run a metric whose actualOutput carries chunk TEXTS and whose
     * expected_output is an answer string (answer-containment@k).
     *
     * PRIVATE: unlike scoreRanked/scoreRankedWithGains (which carry their own
     * empty-set guards and are exercised directly by the unit test), this method
     * does NOT guard k<=0 / empty answer — that guard lives in the public
     * answerContainment() wrapper. Keeping it private prevents a caller from
     * bypassing the guard and hitting the package's degenerate empty-string path.
     *
     * @param  list<array{id:int|string, text:string}>  $rankedChunks  best-first
     */
    private function scoreAnswerContainment(Metric $metric, array $rankedChunks, string $expectedAnswer, ?int $k = null): float
    {
        $retrieved = array_map(
            static fn (array $c): array => ['id' => (string) $c['id'], 'text' => (string) $c['text']],
            $rankedChunks,
        );

        $sample = new DatasetSample(
            id: self::SAMPLE_ID,
            input: [],
            expectedOutput: $expectedAnswer,
            metadata: $k !== null ? ['k' => $k] : [],
        );

        return $metric->score($sample, $this->encodeRetrieved($retrieved))->score;
    }

    /**
     * @param  list<int|string>  $rankedIds
     */
    private function actualOutput(array $rankedIds): string
    {
        $retrieved = array_map(
            static fn ($id): array => ['id' => (string) $id],
            array_values($rankedIds),
        );

        return $this->encodeRetrieved($retrieved);
    }

    /**
     * Encode the package `actualOutput` envelope deterministically. Chunk text
     * can carry invalid UTF-8 byte sequences; JSON_INVALID_UTF8_SUBSTITUTE keeps
     * encoding total (a `�` substitution rather than a silent `false` → ""
     * empty payload that would skew scoring), while JSON_THROW_ON_ERROR turns any
     * other structural failure into an explicit exception instead of a cast-of-false.
     *
     * @param  list<array<string, string>>  $retrieved
     */
    private function encodeRetrieved(array $retrieved): string
    {
        return json_encode(
            ['retrieved' => $retrieved],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
