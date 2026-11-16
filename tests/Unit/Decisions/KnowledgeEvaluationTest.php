<?php

declare(strict_types=1);

namespace Tests\Unit\Decisions;

use App\Services\Kb\Knowledge\KnowledgeEvaluation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KnowledgeEvaluationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(KnowledgeEvaluation::class, new KnowledgeEvaluation(__DIR__.'/../../../resources/fixtures/knowledge'));
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \App\Console\Commands\KbKnowledgeEvaluateCommand);
    }

    private function row(string $split, string $expected, float $score, string $support = 'documented', array $judgment = []): array
    {
        return compact('split', 'expected', 'score', 'support', 'judgment');
    }

    private function header(): array
    {
        return ['dataset_hash' => 'fixture', 'judge_version' => 'knowledge-admission-v2', 'model' => 'typesafe/jev-1.13-20260917'];
    }

    public function test_threshold_uses_calibration_only_and_prefers_highest_equal_recall(): void
    {
        $evaluation = app(KnowledgeEvaluation::class);
        $rows = [$this->row('calibration', 'documented', .73), $this->row('calibration', 'abstain', .03),
            $this->row('validation', 'documented', .68)];
        $report = $evaluation->report($rows, $this->header());
        $this->assertSame(.70, $report['candidate_threshold']);
        $this->assertSame(0, $report['validation']['accepted_positives']);
        $this->assertNull($report['calibration']); // Validation failure must not lower the frozen threshold.
    }

    public function test_no_usable_threshold_reports_measured_abstentions_without_fallback_to_one(): void
    {
        $evaluation = app(KnowledgeEvaluation::class);
        $report = $evaluation->report([$this->row('calibration', 'documented', .60), $this->row('calibration', 'documented', .20)], $this->header());
        $this->assertNull($report['calibration']);
        $this->assertSame(KnowledgeEvaluation::MEASUREMENT_THRESHOLD, $report['metrics_threshold']);
        $this->assertSame(1, $report['metrics']['accepted_positives']);
        $this->assertSame(1, $report['metrics']['abstentions']);
    }

    public function test_wrong_support_is_a_false_approval_and_rejections_are_distinct_from_uncertainty(): void
    {
        $metrics = app(KnowledgeEvaluation::class)->metrics([
            $this->row('validation', 'proposed', .99),
            $this->row('validation', 'abstain', 0, 'insufficient', ['status' => 'rejected']),
            $this->row('validation', 'documented', .60, 'documented', ['status' => 'abstained']),
        ], .7);
        $this->assertSame(1, $metrics['false_approvals']);
        $this->assertSame(1, $metrics['deterministic_rejections']);
        $this->assertSame(1, $metrics['abstentions']);
        $this->assertSame(0, $metrics['provider_errors']);
    }

    public function test_checked_in_paid_report_replays_offline_and_does_not_trust_saved_scores(): void
    {
        Http::preventStrayRequests();
        $path = __DIR__.'/../../../docs/knowledge-evaluation-2026-11-16-calibrated.json';
        $report = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $evaluation = app(KnowledgeEvaluation::class);
        $rows = [];
        foreach ($evaluation->dataset()['cases'] as $case) {
            $saved = collect($report['rows'])->firstWhere('case', $case['id']);
            $rows[] = $evaluation->row($case, $saved['judgment']);
        }
        $computed = $evaluation->report($rows, $this->header());
        $this->assertSame(.70, $computed['candidate_threshold']);
        $this->assertSame(0, $computed['metrics']['false_approvals']);
        $this->assertSame(16, $computed['metrics']['accepted_positives']);
        $this->assertSame(9, $computed['validation']['accepted_positives']);
        $this->assertSame(2, $computed['metrics']['positive_cases'] - $computed['metrics']['accepted_positives']);
        // Corrupt cached scores/labels: replay must recompute from paid answers.
        foreach ($report['rows'] as &$row) { $row['score'] = 1; $row['support'] = 'documented'; }
        unset($row);
        $input = tempnam(sys_get_temp_dir(), 'knowledge-scores-');
        $output = $input.'.result.json';
        try {
            file_put_contents($input, json_encode($report, JSON_THROW_ON_ERROR));
            $this->artisan('kb:knowledge-evaluate', ['--replay' => $input, '--output' => $output])->assertSuccessful();
            $replayed = json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $replayed['metrics']['false_approvals']);
            $this->assertSame(16, $replayed['metrics']['accepted_positives']);
        } finally { unlink($input); if (file_exists($output)) unlink($output); }
        Http::assertNothingSent();
    }

    public function test_replay_refuses_changed_inputs_before_any_provider_call(): void
    {
        Http::preventStrayRequests();
        $report = json_decode(file_get_contents(__DIR__.'/../../../docs/knowledge-evaluation-2026-11-16-calibrated.json'), true, flags: JSON_THROW_ON_ERROR);
        $report['rows'][0]['input_hash'] = 'changed';
        $path = tempnam(sys_get_temp_dir(), 'knowledge-replay-');
        try {
            file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR));
            $this->artisan('kb:knowledge-evaluate', ['--replay' => $path])->assertFailed();
            Http::assertNothingSent();
        } finally { unlink($path); }
    }
}
