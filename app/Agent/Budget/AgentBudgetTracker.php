<?php

declare(strict_types=1);

namespace App\Agent\Budget;

use App\Agent\Tools\AgentToolDefinition;
use App\Models\AgentRun;

final class AgentBudgetTracker
{
    /** @var array<string,mixed> */
    private array $state;

    public function __construct(private readonly AgentRun $run)
    {
        $this->state = array_merge([
            'iterations' => 0,
            'logical_calls' => 0,
            'physical_calls' => 0,
            'consecutive_errors' => 0,
            'evidence_bytes' => 0,
            'signatures' => [],
            'auto_extended' => false,
        ], is_array($run->counters_json) ? $run->counters_json : []);
    }

    public function beginIteration(): AgentBudgetDecision
    {
        if ((int) $this->state['iterations'] >= $this->limit('iterations', 8)) {
            return AgentBudgetDecision::stop('iteration_limit');
        }
        if ($this->elapsedSeconds() >= $this->timeLimit()) {
            return AgentBudgetDecision::stop('time_limit');
        }

        $this->state['iterations'] = (int) $this->state['iterations'] + 1;
        $this->persist();

        return AgentBudgetDecision::allow();
    }

    /** @param array<string,mixed> $arguments */
    public function reserve(AgentToolDefinition $tool, array $arguments, ?int $expectedPhysical = null): AgentBudgetDecision
    {
        if ((int) $this->state['consecutive_errors'] >= $this->limit('consecutive_errors', 3)) {
            return AgentBudgetDecision::stop('consecutive_error_limit');
        }
        if ((int) $this->state['evidence_bytes'] >= $this->limit('evidence_bytes', 524288)) {
            return AgentBudgetDecision::stop('evidence_size_limit');
        }
        if ($this->elapsedSeconds() >= $this->timeLimit()) {
            return AgentBudgetDecision::stop('time_limit');
        }

        $signature = $this->signature($tool->name, $arguments);
        $signatures = is_array($this->state['signatures']) ? $this->state['signatures'] : [];
        if ((int) ($signatures[$signature] ?? 0) >= $this->limit('duplicate_calls', 2)) {
            return AgentBudgetDecision::stop('duplicate_call_limit');
        }

        $nextLogical = (int) $this->state['logical_calls'] + 1;
        $physical = max(0, $expectedPhysical ?? $tool->physicalLikely);
        $nextPhysical = (int) $this->state['physical_calls'] + $physical;
        $logicalHard = $this->logicalHard();
        $physicalHard = $this->physicalHard();
        if ($nextLogical > $logicalHard || $nextPhysical > $physicalHard) {
            return ($tool->readOnly && $tool->idempotent)
                ? AgentBudgetDecision::confirmation($nextLogical > $logicalHard ? 'logical_hard_limit' : 'physical_hard_limit')
                : AgentBudgetDecision::stop('unsafe_hard_limit');
        }

        $autoExtended = false;
        if ($nextLogical > $this->limit('logical_soft', 12) && ! (bool) $this->state['auto_extended']) {
            if (! $tool->readOnly || ! $tool->idempotent || $physical > $physicalHard) {
                return AgentBudgetDecision::confirmation('logical_soft_limit');
            }
            $autoExtended = true;
            $this->state['auto_extended'] = true;
        }

        $this->state['logical_calls'] = $nextLogical;
        $signatures[$signature] = (int) ($signatures[$signature] ?? 0) + 1;
        $this->state['signatures'] = $signatures;
        $this->persist();

        return AgentBudgetDecision::allow($autoExtended);
    }

    public function canIssuePhysical(int $count = 1): AgentBudgetDecision
    {
        return (int) $this->state['physical_calls'] + max(1, $count) <= $this->physicalHard()
            ? AgentBudgetDecision::allow()
            : AgentBudgetDecision::confirmation('physical_hard_limit');
    }

    public function recordResult(int $physicalRequests, int $evidenceBytes, bool $success): void
    {
        $this->state['physical_calls'] = (int) $this->state['physical_calls'] + max(0, $physicalRequests);
        $this->state['evidence_bytes'] = (int) $this->state['evidence_bytes'] + max(0, $evidenceBytes);
        $this->state['consecutive_errors'] = $success
            ? 0
            : (int) $this->state['consecutive_errors'] + 1;
        $this->persist();
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return $this->state + [
            'logical_soft' => $this->limit('logical_soft', 12),
            'logical_hard' => $this->logicalHard(),
            'physical_hard' => $this->physicalHard(),
            'time_limit_seconds' => $this->timeLimit(),
        ];
    }

    private function logicalHard(): int
    {
        return $this->limit('logical_hard', 25)
            + (int) data_get($this->run->budget_json, 'confirmed_logical_extension', 0);
    }

    private function physicalHard(): int
    {
        return $this->limit('physical_hard', 100)
            + (int) data_get($this->run->budget_json, 'confirmed_physical_extension', 0);
    }

    private function timeLimit(): int
    {
        return (int) $this->state['physical_calls'] > 1
            ? $this->limit('bulk_time_seconds', 90)
            : $this->limit('interactive_time_seconds', 60);
    }

    private function elapsedSeconds(): float
    {
        $start = $this->run->started_at ?? $this->run->created_at ?? now();

        return max(0, now()->floatDiffInSeconds($start));
    }

    private function limit(string $key, int $default): int
    {
        return max(1, (int) config('agent.limits.'.$key, $default));
    }

    /** @param array<string,mixed> $arguments */
    private function signature(string $tool, array $arguments): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
            return array_map($normalize, $value);
        };

        return hash('sha256', $tool."\0".json_encode($normalize($arguments), JSON_UNESCAPED_UNICODE));
    }

    private function persist(): void
    {
        $this->run->forceFill(['counters_json' => $this->state])->save();
    }
}
