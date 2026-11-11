<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\Knowledge\KnowledgeContract;
use App\Services\Kb\Knowledge\KnowledgeExtractor;
use Illuminate\Console\Command;

final class KbKnowledgeEvaluateCommand extends Command
{
    protected $signature = 'kb:knowledge-evaluate {--live : Call Jev on anonymized labeled fixtures} {--output= : Save measured results for audit and calibration}';
    protected $description = 'Measure entity association, admission, support, abstentions, latency and cost on labeled synthetic cases';

    public function handle(KnowledgeExtractor $extractor): int
    {
        $path = resource_path('fixtures/knowledge/acceptance.json');
        $cases = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! $this->option('live')) {
            $this->line(json_encode(['mode' => 'preview', 'cases' => $cases], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }
        $blocks = app(KnowledgeContract::class)->template();
        $blocks['admission']['calibration'] = ['threshold' => 0.500001, 'reference' => 'measurement-only'];
        $rows = [];
        foreach ($cases as $case) {
            $candidate = $extractor->validate($case['candidate'], $case['source'], ['blocks' => $blocks]);
            $judgment = $candidate === null ? ['status' => 'rejected', 'reason' => 'invalid_identity_or_quote'] : $extractor->judge($candidate, ['blocks' => $blocks]);
            $decision = $judgment['decision'] ?? [];
            $score = min((float) data_get($decision, 'answers.association.probability_true', 0), (float) data_get($decision, 'answers.admission.probability_true', 0),
                (float) data_get($decision, 'answers.support.confidence', 0), (float) data_get($decision, 'answers.support.probabilities.'.data_get($decision, 'answers.support.choice', ''), 0));
            if (data_get($decision, 'answers.support.choice') === 'insufficient') $score = 0;
            $rows[] = ['case' => $case['id'], 'split' => $case['split'], 'expected' => $case['expected'], 'score' => $score,
                'support' => data_get($decision, 'answers.support.choice'), 'judgment' => $judgment];
            $this->getOutput()->write('.');
        }
        $best = null; $bestRecall = -1;
        foreach ([0.51, 0.60, 0.70, 0.80, 0.85, 0.90, 0.92, 0.95, 0.97, 0.99] as $threshold) {
            $metrics = $this->metrics(array_filter($rows, fn ($r) => $r['split'] === 'calibration'), $threshold);
            if ($metrics['false_approvals'] === 0 && $metrics['recall'] >= 0.75 && $metrics['recall'] > $bestRecall) { $best = $threshold; $bestRecall = $metrics['recall']; }
        }
        $validation = $best === null ? null : $this->metrics(array_filter($rows, fn ($r) => $r['split'] === 'validation'), $best);
        $usable = $validation !== null && $validation['false_approvals'] === 0 && $validation['recall'] >= 0.75;
        $result = ['dataset_hash' => hash_file('sha256', $path), 'model' => config('decisions.models.jev.model'), 'measured_at' => now()->toIso8601String(),
            'scope' => 'synthetic_regression_baseline; validate company examples before production',
            'calibration' => $usable ? ['threshold' => $best, 'reference' => 'synthetic:'.hash_file('sha256', $path)] : null,
            'validation' => $validation, 'metrics' => $this->metrics($rows, $best ?? 1), 'rows' => $rows];
        $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($this->option('output')) file_put_contents($this->option('output'), $encoded."\n");
        $this->newLine(); $this->line($encoded);
        return $usable ? self::SUCCESS : self::FAILURE;
    }

    private function metrics(array $rows, float $threshold): array
    {
        $falseApprovals = $positive = $acceptedPositive = $abstentions = $errors = 0;
        $cost = $latency = 0.0;
        foreach ($rows as $row) {
            $expected = $row['expected'] !== 'abstain';
            $accepted = $row['score'] >= $threshold && $row['support'] !== 'insufficient';
            $correct = $accepted && $expected && $row['support'] === $row['expected'];
            $positive += (int) $expected; $acceptedPositive += (int) $correct;
            $falseApprovals += (int) ($accepted && ! $correct); $abstentions += (int) ! $accepted;
            $errors += (int) isset($row['judgment']['error']);
            $cost += (float) data_get($row, 'judgment.decision.usage.cost', 0);
            $latency += (float) data_get($row, 'judgment.decision.latency_ms', data_get($row, 'judgment.elapsed_ms', 0));
        }
        return ['cases' => count($rows), 'false_approvals' => $falseApprovals, 'recall' => $positive ? $acceptedPositive / $positive : 0,
            'abstentions' => $abstentions, 'provider_errors' => $errors, 'known_cost_usd' => $cost, 'latency_ms' => $latency];
    }
}
