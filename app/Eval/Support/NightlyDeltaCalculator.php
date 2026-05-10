<?php

declare(strict_types=1);

namespace App\Eval\Support;

/**
 * Compute the macro_f1 + per-metric delta between two eval-harness JSON
 * report payloads. Used by EvalNightlyCommand to decide whether the
 * latest nightly run regressed against the prior baseline.
 *
 * Both inputs are decoded report arrays as produced by
 * Padosoft\EvalHarness\Reports\JsonReportRenderer (shape documented in
 * its class header). Only the `macro_f1` and `metrics.<name>.mean`
 * keys are consulted; missing keys are treated as 0.0 so a malformed
 * report surfaces as a regression rather than silently passing.
 *
 * Why not a value object: the result is consumed once by the command
 * and serialised to a sidecar JSON; an array keeps the wire format
 * obvious without an extra mapping layer.
 */
final class NightlyDeltaCalculator
{
    /**
     * @param  array<string, mixed>|null  $prior
     * @param  array<string, mixed>  $current
     * @return array{
     *     macro_f1_prior: float,
     *     macro_f1_current: float,
     *     macro_f1_delta: float,
     *     regressed_metrics: list<array{name: string, prior: float, current: float, delta: float}>,
     *     improved_metrics: list<array{name: string, prior: float, current: float, delta: float}>
     * }|null
     */
    public function compute(?array $prior, array $current): ?array
    {
        if ($prior === null) {
            return null;
        }

        $priorMacroF1 = self::asFloat($prior['macro_f1'] ?? null);
        $currentMacroF1 = self::asFloat($current['macro_f1'] ?? null);

        $priorMetrics = self::metricMeans($prior);
        $currentMetrics = self::metricMeans($current);

        $regressed = [];
        $improved = [];

        $allNames = array_unique(array_merge(array_keys($priorMetrics), array_keys($currentMetrics)));
        sort($allNames);

        foreach ($allNames as $name) {
            $p = $priorMetrics[$name] ?? 0.0;
            $c = $currentMetrics[$name] ?? 0.0;
            $delta = $c - $p;

            if ($delta < 0.0) {
                $regressed[] = [
                    'name' => $name,
                    'prior' => $p,
                    'current' => $c,
                    'delta' => $delta,
                ];

                continue;
            }

            if ($delta > 0.0) {
                $improved[] = [
                    'name' => $name,
                    'prior' => $p,
                    'current' => $c,
                    'delta' => $delta,
                ];
            }
        }

        return [
            'macro_f1_prior' => $priorMacroF1,
            'macro_f1_current' => $currentMacroF1,
            'macro_f1_delta' => $currentMacroF1 - $priorMacroF1,
            'regressed_metrics' => $regressed,
            'improved_metrics' => $improved,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, float>
     */
    private static function metricMeans(array $report): array
    {
        $metrics = $report['metrics'] ?? null;
        if (! is_array($metrics)) {
            return [];
        }

        $out = [];
        foreach ($metrics as $name => $aggregate) {
            if (! is_string($name) || ! is_array($aggregate)) {
                continue;
            }
            $out[$name] = self::asFloat($aggregate['mean'] ?? null);
        }

        return $out;
    }

    private static function asFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
