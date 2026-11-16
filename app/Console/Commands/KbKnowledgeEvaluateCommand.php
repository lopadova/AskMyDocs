<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\Knowledge\KnowledgeContract;
use App\Services\Kb\Knowledge\KnowledgeEvaluation;
use App\Services\Kb\Knowledge\KnowledgeExtractor;
use Illuminate\Console\Command;

final class KbKnowledgeEvaluateCommand extends Command
{
    protected $signature = 'kb:knowledge-evaluate {--live : Call Jev on anonymized labeled fixtures} {--replay= : Recompute a saved report offline without provider calls} {--output= : Save measured results and per-case checkpoints}';
    protected $description = 'Measure entity association, admission, support, abstentions, latency and cost on labeled synthetic cases';

    public function handle(KnowledgeExtractor $extractor, KnowledgeEvaluation $evaluation): int
    {
        $dataset = $evaluation->dataset();
        if (! $this->option('live') && ! $this->option('replay')) {
            $this->line(json_encode(['mode' => 'preview', 'dataset_hash' => $dataset['hash'], 'cases' => $dataset['cases']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }
        if ($this->option('live') && $this->option('replay')) { $this->error('Choose live measurement or offline replay.'); return self::FAILURE; }
        if ($this->option('live') && ! $this->option('output')) { $this->error('Live measurements require --output to checkpoint paid responses.'); return self::FAILURE; }
        if ($this->option('output') && file_exists($this->option('output'))) { $this->error('Use a new output path to preserve paid measurements.'); return self::FAILURE; }
        $header = ['dataset_hash' => $dataset['hash'], 'datasets' => $dataset['files'], 'judge_version' => KnowledgeExtractor::JUDGE_VERSION,
            'model' => config('decisions.models.knowledge_jev.model'), 'measured_at' => now()->toIso8601String()];
        $rows = [];
        if ($this->option('replay')) {
            try {
                $saved = json_decode(file_get_contents($this->option('replay')), true, flags: JSON_THROW_ON_ERROR);
                foreach (['dataset_hash', 'judge_version', 'model'] as $field) {
                    if (($saved[$field] ?? null) !== $header[$field]) throw new \RuntimeException('Replay dataset, judge or model does not match.');
                }
                $indexed = collect($saved['rows'])->keyBy('case');
                if ($indexed->count() !== count($dataset['cases']) || count($saved['rows']) !== $indexed->count()) throw new \RuntimeException('Replay must contain each case exactly once.');
                foreach ($dataset['cases'] as $case) {
                    $old = $indexed->get($case['id']);
                    if (! is_array($old)) throw new \RuntimeException('Missing replay case.');
                    $row = $evaluation->row($case, $old['judgment']);
                    if (($old['input_hash'] ?? null) !== $row['input_hash']) throw new \RuntimeException('Replay input mismatch.');
                    $decision = $old['judgment']['decision'] ?? null;
                    if ($decision !== null && ($decision['model'] ?? null) !== $header['model']) throw new \RuntimeException('Replay response model mismatch.');
                    $rows[] = $row;
                }
                foreach (['measurement_runs', 'frozen_protocol', 'protocol_frozen_at', 'notes', 'completed_at', 'extractor_version'] as $field) {
                    if (isset($saved[$field])) $header[$field] = $saved[$field];
                }
                $header['measured_at'] = $saved['measured_at'];
                $header['replayed_at'] = now()->toIso8601String();
            } catch (\Throwable $e) { $this->error('Cannot replay: '.$e->getMessage()); return self::FAILURE; }
        } else {
            $this->save($this->option('output'), [...$header, 'complete' => false, 'rows' => []]);
            $blocks = app(KnowledgeContract::class)->template();
            $blocks['admission']['calibration'] = ['threshold' => KnowledgeEvaluation::MEASUREMENT_THRESHOLD,
                'reference' => 'measurement-only', 'judge_version' => $header['judge_version'], 'model' => $header['model']];
            foreach ($dataset['cases'] as $case) {
                $candidate = $extractor->validate($case['candidate'], $case['source'], ['blocks' => $blocks]);
                $judgment = $candidate === null ? ['status' => 'rejected', 'reason' => 'invalid_identity_or_quote'] : $extractor->judge($candidate, ['blocks' => $blocks]);
                $rows[] = $evaluation->row($case, $judgment);
                // Save each paid result immediately, even if a later case fails.
                if ($this->option('output')) $this->save($this->option('output'), [...$header, 'complete' => false, 'rows' => $rows]);
                $this->getOutput()->write('.');
            }
        }
        $result = $evaluation->report($rows, [...$header, 'complete' => true]);
        if ($this->option('output')) $this->save($this->option('output'), $result);
        $this->newLine(); $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $result['calibration'] !== null ? self::SUCCESS : self::FAILURE;
    }

    private function save(string $path, array $data): void
    {
        $temporary = tempnam(dirname($path), '.knowledge-evaluation-');
        if ($temporary === false) throw new \RuntimeException('Cannot create evaluation checkpoint.');
        try {
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($temporary, $encoded) === false || ! rename($temporary, $path)) throw new \RuntimeException('Cannot save evaluation checkpoint.');
        } finally {
            if (file_exists($temporary)) unlink($temporary);
        }
    }
}
