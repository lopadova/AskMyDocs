<?php

namespace App\Console\Commands;

use App\Agent\Grounding\FocusedClaimEvaluator;
use Illuminate\Console\Command;

/** Synthetic, anonymized diagnostic cases; never reads or changes business data. */
class DecisionReasoningEvalCommand extends Command
{
    protected $signature = 'decision:reasoning-eval {--live : Call the configured decision provider (AI usage applies)} {--json}';

    protected $description = 'Evaluate focused grounding on anonymized regression cases; no network unless --live.';

    public function handle(FocusedClaimEvaluator $evaluator): int
    {
        $cases = json_decode(file_get_contents(resource_path('fixtures/reasoning/validation.json')), true, flags: JSON_THROW_ON_ERROR);
        if (! $this->option('live')) {
            $this->line(json_encode(['mode' => 'preview', 'cases' => $cases], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }
        $rows = [];
        $metrics = ['cases' => count($cases), 'false_approvals' => 0, 'false_rejections' => 0, 'confident_false_rejections' => 0,
            'abstentions' => 0, 'repetitions' => 0, 'provider_errors' => 0, 'cost' => 0, 'latency_ms' => 0];
        foreach ($cases as $case) {
            $hash = hash('sha256', $case['source']);
            $focus = ['topic' => $case['question'], 'identifiers' => [$case['identifier']], 'aspect' => $case['question'], 'fields' => []];
            $documents = [['document_id' => 1, 'evidence' => [['content' => $case['source'], 'evidence_hash' => $hash]]]];
            if (isset($case['competing_source'])) {
                $documents[] = ['document_id' => 2, 'evidence' => [['content' => $case['competing_source'], 'evidence_hash' => hash('sha256', $case['competing_source'])]]];
            }
            $communicated = ($case['already_communicated'] ?? false)
                ? [hash('sha256', $case['source'].'|'.mb_strtolower(trim(strip_tags($case['claim']))))] : [];
            $result = $evaluator->evaluate($case['question'], ['documents' => $documents],
                [['text' => $case['claim'], 'document_id' => 1, 'evidence_hash' => $hash]], ['focus' => $focus, 'subquestions' => [$focus]], communicated: $communicated);
            $meta = $result['semantic_validation'][0] ?? [];
            $check = $meta['checks'][0] ?? [];
            $expectedAccepted = $case['expected'] === 'supported_relevant' && $communicated === [];
            $metrics['false_approvals'] += (int) ($result['valid'] && ! $expectedAccepted);
            $metrics['false_rejections'] += (int) (! $result['valid'] && $expectedAccepted);
            $metrics['confident_false_rejections'] += (int) (! $result['valid'] && $expectedAccepted && ($check['status'] ?? '') === 'rejected');
            $metrics['abstentions'] += (int) (($check['status'] ?? '') === 'inconclusive');
            $metrics['provider_errors'] += (int) (($meta['status'] ?? '') === 'error');
            $metrics['repetitions'] += (int) ($communicated !== [] && $result['valid']);
            $metrics['cost'] += $meta['usage']['cost'] ?? 0;
            $metrics['latency_ms'] += $meta['latency_ms'] ?? 0;
            $rows[] = ['case' => $case['name'], 'expected' => $case['expected'], 'category' => $check['category'] ?? null,
                'probability' => $check['probability'] ?? null, 'accepted' => $result['valid'], 'model' => $meta['model'] ?? null,
                'reason' => $check['reason'] ?? null];
        }
        if ($this->option('json')) {
            $this->line(json_encode(['metrics' => $metrics, 'results' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Case', 'Expected', 'Category', 'Probability', 'Accepted', 'Model', 'Reason'], array_map(fn ($r) => array_values([...$r, 'accepted' => $r['accepted'] ? 'yes' : 'no']), $rows));
            $this->line(json_encode($metrics));
        }
        return $metrics['false_approvals'] > 0 || $metrics['provider_errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
