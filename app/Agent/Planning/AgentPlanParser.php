<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\AgentProgress;
use App\Agent\Tools\AgentToolDefinition;

final class AgentPlanParser
{
    /** @param array<string,mixed> $payload @param array<string,AgentToolDefinition> $tools */
    public function parse(array $payload, array $tools, int $maximum = 8): AgentPlan
    {
        $decision = (string) ($payload['decision'] ?? '');
        if (! in_array($decision, ['tools', 'answer', 'insufficient'], true)) {
            throw new \UnexpectedValueException('Planner returned an invalid decision.');
        }

        $rawActions = is_array($payload['actions'] ?? null) ? array_values($payload['actions']) : [];
        $maximum = max(1, $maximum);
        if (count($rawActions) > $maximum || ($decision !== 'tools' && $rawActions !== [])) {
            throw new \UnexpectedValueException('Planner returned an invalid action count.');
        }

        $actions = [];
        $knownIds = [];
        foreach ($rawActions as $index => $raw) {
            if (! is_array($raw)) {
                throw new \UnexpectedValueException('Planner action must be an object.');
            }
            $id = trim((string) ($raw['id'] ?? 'step_'.($index + 1)));
            $toolName = trim((string) ($raw['tool'] ?? ''));
            $purpose = trim((string) ($raw['purpose'] ?? ''));
            $dependsOn = array_values(array_filter(
                is_array($raw['depends_on'] ?? null) ? $raw['depends_on'] : [],
                'is_string',
            ));
            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id) !== 1 || isset($knownIds[$id])) {
                throw new \UnexpectedValueException("Planner action id [{$id}] is invalid or duplicated.");
            }
            if (! isset($tools[$toolName])) {
                throw new \UnexpectedValueException("Planner selected unknown tool [{$toolName}].");
            }
            foreach ($dependsOn as $dependency) {
                if (! isset($knownIds[$dependency])) {
                    throw new \UnexpectedValueException("Planner dependency [{$dependency}] must reference an earlier action.");
                }
            }
            if ($purpose === '' || mb_strlen($purpose) > 160) {
                throw new \UnexpectedValueException('Planner purpose must be a short operational label.');
            }

            $arguments = is_array($raw['arguments'] ?? null) ? $raw['arguments'] : [];
            $actions[] = new AgentPlannedAction($id, $toolName, $arguments, $dependsOn, $purpose);
            $knownIds[$id] = true;
        }

        if ($decision === 'tools' && $actions === []) {
            throw new \UnexpectedValueException('A tools decision requires at least one action.');
        }

        return new AgentPlan($decision, $actions, $this->estimate($actions, $tools));
    }

    /** @param list<AgentPlannedAction> $actions @param array<string,AgentToolDefinition> $tools */
    private function estimate(array $actions, array $tools): AgentProgress
    {
        $min = $likely = $max = 0;
        foreach ($actions as $action) {
            $tool = $tools[$action->tool];
            $min += $tool->physicalMinimum;
            $likely += $tool->physicalLikely;
            $max += $tool->physicalMaximum;
        }

        return new AgentProgress(
            logicalMinimum: count($actions),
            logicalLikely: count($actions),
            logicalMaximum: count($actions),
            physicalMinimum: $min,
            physicalLikely: $likely,
            physicalMaximum: $max,
        );
    }
}
