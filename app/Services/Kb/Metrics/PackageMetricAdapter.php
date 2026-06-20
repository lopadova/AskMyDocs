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
 * returns the raw `float` score. `k` is passed per-sample as `metadata.k`
 * (the metric falls back to `eval-harness.metrics.retrieval.default_k`).
 *
 * @internal Use through {@see RetrievalQualityMetrics}, not directly from callers.
 */
final class PackageMetricAdapter
{
    private const SAMPLE_ID = 'askmydocs-inproc';

    public function __construct(private readonly ConfigRepository $configRepo) {}

    public function config(): ConfigRepository
    {
        return $this->configRepo;
    }

    // ---------------------------------------------------------------------
    // High-level resolvers — callers (RetrievalQualityMetrics) use THESE so
    // they never name a package metric class; this adapter resolves the metric
    // from the container (which auto-wires its ConfigRepository).
    // ---------------------------------------------------------------------

    /**
     * MRR over a ranked-id list + a flat relevant set.
     *
     * @param  list<int|string>  $rankedIds
     * @param  list<int|string>  $relevantIds
     */
    public function scoreMrr(array $rankedIds, array $relevantIds, ?int $k = null): float
    {
        return $this->scoreRanked(app(RetrievalMrrMetric::class), $rankedIds, $relevantIds, $k);
    }

    /**
     * nDCG@k over a ranked-id list + a graded {id:grade} map.
     *
     * @param  list<int|string>  $rankedIds
     * @param  array<int|string, int|float>  $gains
     */
    public function scoreNdcg(array $rankedIds, array $gains, ?int $k = null): float
    {
        return $this->scoreRankedWithGains(app(RetrievalNdcgAtKMetric::class), $rankedIds, $gains, $k);
    }

    /**
     * Answer-containment@k over ranked chunk texts + an expected answer string.
     *
     * @param  list<array{id:int|string, text:string}>  $rankedChunks  best-first
     */
    public function answerContainment(array $rankedChunks, string $expectedAnswer, ?int $k = null): float
    {
        return $this->scoreAnswerContainment(app(AnswerContainmentAtKMetric::class), $rankedChunks, $expectedAnswer, $k);
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
     * Run a metric whose expected_output is a graded {id:grade} map (nDCG@k).
     *
     * @param  list<int|string>  $rankedIds
     * @param  array<int|string, int|float>  $gains
     */
    public function scoreRankedWithGains(Metric $metric, array $rankedIds, array $gains, ?int $k = null): float
    {
        // Same behaviour-preservation as scoreRanked(): an empty gains map meant
        // IDCG = 0 → nDCG 0.0 in the old code; the package would throw on the
        // empty expected_output instead. Short-circuit to 0.0.
        if ($gains === []) {
            return 0.0;
        }

        $graded = [];
        foreach ($gains as $id => $grade) {
            $graded[(string) $id] = (float) $grade;
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
     * @param  list<array{id:int|string, text:string}>  $rankedChunks  best-first
     */
    public function scoreAnswerContainment(Metric $metric, array $rankedChunks, string $expectedAnswer, ?int $k = null): float
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
