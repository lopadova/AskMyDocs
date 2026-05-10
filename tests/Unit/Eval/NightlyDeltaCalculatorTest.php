<?php

declare(strict_types=1);

namespace Tests\Unit\Eval;

use App\Eval\Support\NightlyDeltaCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see NightlyDeltaCalculator}.
 *
 * Each test exercises ONE invariant exactly as the body implies (R16).
 */
final class NightlyDeltaCalculatorTest extends TestCase
{
    public function test_returns_null_when_no_prior_report(): void
    {
        $calculator = new NightlyDeltaCalculator;

        $result = $calculator->compute(null, $this->report(0.9, ['contains' => 0.9]));

        $this->assertNull($result, 'A first nightly run has no prior baseline to compare against.');
    }

    public function test_computes_macro_f1_delta_correctly(): void
    {
        $calculator = new NightlyDeltaCalculator;

        $result = $calculator->compute(
            $this->report(0.92, ['contains' => 0.9]),
            $this->report(0.85, ['contains' => 0.8]),
        );

        $this->assertNotNull($result);
        $this->assertSame(0.92, $result['macro_f1_prior']);
        $this->assertSame(0.85, $result['macro_f1_current']);
        $this->assertEqualsWithDelta(-0.07, $result['macro_f1_delta'], 1e-9);
    }

    public function test_identifies_regressed_metrics(): void
    {
        $calculator = new NightlyDeltaCalculator;

        $result = $calculator->compute(
            $this->report(0.9, ['contains' => 0.9, 'cosine-embedding' => 0.85]),
            $this->report(0.8, ['contains' => 0.7, 'cosine-embedding' => 0.85]),
        );

        $this->assertNotNull($result);
        $this->assertCount(1, $result['regressed_metrics']);
        $this->assertSame('contains', $result['regressed_metrics'][0]['name']);
        $this->assertEqualsWithDelta(-0.2, $result['regressed_metrics'][0]['delta'], 1e-9);
        $this->assertSame([], $result['improved_metrics']);
    }

    public function test_identifies_improved_metrics(): void
    {
        $calculator = new NightlyDeltaCalculator;

        $result = $calculator->compute(
            $this->report(0.7, ['contains' => 0.6, 'cosine-embedding' => 0.5]),
            $this->report(0.85, ['contains' => 0.6, 'cosine-embedding' => 0.9]),
        );

        $this->assertNotNull($result);
        $this->assertSame([], $result['regressed_metrics']);
        $this->assertCount(1, $result['improved_metrics']);
        $this->assertSame('cosine-embedding', $result['improved_metrics'][0]['name']);
        $this->assertEqualsWithDelta(0.4, $result['improved_metrics'][0]['delta'], 1e-9);
    }

    /**
     * @param  array<string, float>  $metricMeans
     * @return array<string, mixed>
     */
    private function report(float $macroF1, array $metricMeans): array
    {
        $metrics = [];
        foreach ($metricMeans as $name => $mean) {
            $metrics[$name] = [
                'mean' => $mean,
                'p50' => $mean,
                'p95' => $mean,
                'pass_rate' => $mean,
            ];
        }

        return [
            'macro_f1' => $macroF1,
            'metrics' => $metrics,
        ];
    }
}
