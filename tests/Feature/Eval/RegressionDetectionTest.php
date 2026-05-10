<?php

declare(strict_types=1);

namespace Tests\Feature\Eval;

use App\Eval\EvalRegistrar;
use App\Eval\Metrics\CitationGroundednessMetric;
use App\Eval\Metrics\CosineGroundednessMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Reports\EvalReport;
use Tests\TestCase;

/**
 * R16: PROOF that the regression gate actually detects regressions.
 *
 * Test plan:
 *   1. Run the registered baseline metric stack against a SUT that
 *      returns the canonical answer + correct citations. Assert the
 *      report is "green" (zero captured failures, every metric scores
 *      well).
 *   2. Run the SAME metric stack against a SUT that returns a
 *      hallucinated answer + a fabricated citation (the regression
 *      simulation). Assert the report is "red": at least one metric
 *      drops AND CitationGroundednessMetric flags the fabrication.
 *
 * This test does NOT call eval-harness:run as a CLI; it exercises the
 * EvalEngine surface directly so the test stays under 1s and the
 * assertions inspect the structured report rather than parsing CLI
 * output.
 */
final class RegressionDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('eval-harness.askmydocs.live_ai', false);
    }

    public function test_baseline_run_against_canonical_sut_yields_high_scores(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $sample = $this->canonicalSample();
        $engine->dataset('regression-baseline')
            ->withSamples([$sample])
            ->withMetrics(['contains', CitationGroundednessMetric::class])
            ->register();

        // Canonical SUT — the answer matches expected_output and the
        // citation matches expected_citations exactly.
        $sut = function (array $input) use ($sample): string {
            return json_encode([
                'answer' => $sample->expectedOutput,
                'citations' => [['source_path' => 'policies/remote-work-policy.md']],
                'meta' => ['project_key' => $input['project_key']],
            ], JSON_THROW_ON_ERROR);
        };

        $report = $engine->run('regression-baseline', $sut);

        $this->assertSame(0, $this->totalFailures($report), 'Baseline run must capture zero metric failures.');
        $this->assertGreaterThanOrEqual(0.99, $this->meanScore($report, 'citation-groundedness-strict'));
        $this->assertGreaterThanOrEqual(0.99, $this->meanScore($report, 'contains'));
    }

    public function test_regression_sut_with_hallucinated_citation_drops_score_below_baseline(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $sample = $this->canonicalSample();
        $engine->dataset('regression-red')
            ->withSamples([$sample])
            ->withMetrics(['contains', CitationGroundednessMetric::class])
            ->register();

        // Regression SUT — answer drifted AND a fabricated citation
        // is emitted in place of the canonical one.
        $sut = static function (array $input): string {
            return json_encode([
                'answer' => 'Something completely unrelated about banana ripening curves.',
                'citations' => [['source_path' => 'fake/hallucinated.md']],
                'meta' => ['project_key' => $input['project_key']],
            ], JSON_THROW_ON_ERROR);
        };

        $report = $engine->run('regression-red', $sut);

        // Citation-groundedness MUST flag the hallucinated citation:
        // expected ['policies/remote-work-policy.md'] vs actual
        // ['fake/hallucinated.md'] → 0 hits, 1 miss → 0.0.
        $citationScore = $this->meanScore($report, 'citation-groundedness-strict');
        $this->assertLessThanOrEqual(
            0.0,
            $citationScore,
            'CitationGroundednessMetric must score 0 when the cited path neither matches expected nor resolves to a real seeded doc.',
        );

        // Contains metric must drop too (the hallucinated answer does
        // NOT contain the expected substring).
        $containsScore = $this->meanScore($report, 'contains');
        $this->assertLessThan(0.5, $containsScore);
    }

    public function test_baseline_minus_regression_delta_proves_the_gate_works(): void
    {
        // Direct A/B contrast: same dataset, same metrics, same engine
        // — only the SUT differs. The DELTA is the regression signal.
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $sample = $this->canonicalSample();
        $engine->dataset('regression-ab')
            ->withSamples([$sample])
            ->withMetrics([CitationGroundednessMetric::class])
            ->register();

        $canonicalSut = function (array $input) use ($sample): string {
            return json_encode([
                'answer' => $sample->expectedOutput,
                'citations' => [['source_path' => 'policies/remote-work-policy.md']],
                'meta' => ['project_key' => $input['project_key']],
            ], JSON_THROW_ON_ERROR);
        };
        $regressionSut = static function (array $input): string {
            return json_encode([
                'answer' => 'Wrong.',
                'citations' => [['source_path' => 'fake/hallucinated.md']],
                'meta' => ['project_key' => $input['project_key']],
            ], JSON_THROW_ON_ERROR);
        };

        $baselineMean = $this->meanScore($engine->run('regression-ab', $canonicalSut), 'citation-groundedness-strict');
        $regressionMean = $this->meanScore($engine->run('regression-ab', $regressionSut), 'citation-groundedness-strict');

        // Strict comparison (>) — under R16, sorting/ordering tests use
        // strictly-monotonic fixtures and strict comparators.
        $this->assertGreaterThan(
            $regressionMean,
            $baselineMean,
            'CitationGroundednessMetric mean must DROP when the SUT regresses (baseline > regression).',
        );
    }

    private function canonicalSample(): DatasetSample
    {
        return new DatasetSample(
            id: 'remote-days',
            input: ['question' => 'How many remote days per week?', 'project_key' => 'hr-portal'],
            expectedOutput: 'Up to 3 days per week with manager approval.',
            metadata: ['expected_citations' => ['policies/remote-work-policy.md']],
        );
    }

    private function totalFailures(EvalReport $report): int
    {
        return $report->totalFailures();
    }

    private function meanScore(EvalReport $report, string $metricName): float
    {
        return $report->meanScore($metricName);
    }
}
