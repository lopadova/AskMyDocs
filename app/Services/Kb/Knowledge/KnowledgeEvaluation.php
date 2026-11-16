<?php

declare(strict_types=1);

namespace App\Services\Kb\Knowledge;

/** Offline metrics use exactly the same admission score as publication. */
final class KnowledgeEvaluation
{
    public const MEASUREMENT_THRESHOLD = 0.500001;
    public const THRESHOLDS = [0.51, 0.60, 0.70, 0.80, 0.85, 0.90, 0.92, 0.95, 0.97, 0.99];

    public function __construct(private readonly ?string $fixturePath = null) {}

    public function dataset(): array
    {
        $files = $cases = [];
        foreach (['acceptance', 'adversarial'] as $name) {
            $path = ($this->fixturePath ?? resource_path('fixtures/knowledge'))."/{$name}.json";
            $files[$name] = hash_file('sha256', $path);
            array_push($cases, ...json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
        }
        return ['hash' => hash('sha256', json_encode($files, JSON_THROW_ON_ERROR)), 'files' => $files, 'cases' => $cases];
    }

    public function row(array $case, array $judgment): array
    {
        $score = app(KnowledgeExtractor::class)->score($judgment['decision'] ?? []);
        if (isset($judgment['error']) || ($judgment['status'] ?? '') === 'rejected'
            || in_array($judgment['reason'] ?? '', ['calibration_model_mismatch', 'calibration_version_mismatch', 'invalid_calibration'], true)) $score = 0;
        return ['case' => $case['id'], 'input_hash' => hash('sha256', json_encode($case, JSON_THROW_ON_ERROR)),
            'split' => $case['split'], 'expected' => $case['expected'], 'score' => $score,
            'support' => data_get($judgment, 'decision.answers.support.choice'), 'judgment' => $judgment];
    }

    public function report(array $rows, array $header): array
    {
        $best = null; $bestRecall = -1; $analysis = [];
        foreach (self::THRESHOLDS as $threshold) {
            $metrics = $this->metrics(array_filter($rows, fn ($r) => $r['split'] === 'calibration'), $threshold);
            $analysis[] = ['threshold' => $threshold, 'calibration' => $metrics];
            // Prefer the highest tested threshold among equally sensitive choices.
            if ($metrics['false_approvals'] === 0 && $metrics['recall'] >= 0.75 && $metrics['recall'] >= $bestRecall) {
                $best = $threshold; $bestRecall = $metrics['recall'];
            }
        }
        $validationRows = array_filter($rows, fn ($r) => $r['split'] === 'validation');
        $validation = $best === null || $validationRows === [] ? null : $this->metrics($validationRows, $best);
        $usable = $validation !== null && $validation['false_approvals'] === 0 && $validation['recall'] >= 0.75;
        $threshold = $best ?? self::MEASUREMENT_THRESHOLD;
        return [...$header, 'scope' => 'synthetic_regression; validate company examples before production',
            'candidate_threshold' => $best,
            'calibration' => $usable ? ['threshold' => $best, 'reference' => 'synthetic:'.$header['dataset_hash'],
                'judge_version' => $header['judge_version'], 'model' => $header['model']] : null,
            'calibration_metrics' => $this->metrics(array_filter($rows, fn ($r) => $r['split'] === 'calibration'), $threshold),
            'validation' => $validation, 'metrics_threshold' => $threshold, 'metrics' => $this->metrics($rows, $threshold),
            'measurement_metrics' => $this->metrics($rows, self::MEASUREMENT_THRESHOLD), 'threshold_analysis' => $analysis, 'rows' => array_values($rows)];
    }

    public function metrics(array $rows, float $threshold): array
    {
        $falseApprovals = $positive = $correct = $abstentions = $rejected = $errors = 0;
        $cost = $latency = 0.0;
        foreach ($rows as $row) {
            $expected = $row['expected'] !== 'abstain';
            $accepted = $row['score'] >= $threshold && in_array($row['support'], ['documented', 'proposed', 'contradictory'], true);
            $match = $accepted && $expected && $row['support'] === $row['expected'];
            $isRejected = ($row['judgment']['status'] ?? '') === 'rejected';
            $positive += (int) $expected; $correct += (int) $match;
            $falseApprovals += (int) ($accepted && ! $match);
            $abstentions += (int) (! $accepted && ! $isRejected); $rejected += (int) $isRejected;
            $errors += (int) isset($row['judgment']['error']);
            $cost += (float) data_get($row, 'judgment.decision.usage.cost', 0);
            $latency += (float) data_get($row, 'judgment.decision.latency_ms', data_get($row, 'judgment.elapsed_ms', 0));
        }
        return ['cases' => count($rows), 'positive_cases' => $positive, 'negative_cases' => count($rows) - $positive,
            'accepted_positives' => $correct, 'false_approvals' => $falseApprovals, 'recall' => $positive ? $correct / $positive : 0,
            'abstentions' => $abstentions, 'deterministic_rejections' => $rejected, 'provider_errors' => $errors,
            'known_cost_usd' => $cost, 'latency_ms' => $latency];
    }
}
