<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\Tools\AgentToolDefinition;

final readonly class AgentLiveSourceCoverage
{
    public function __construct(private AgentExhaustiveSourceIntent $intent) {}

    /**
     * @param  array<string,AgentToolDefinition>  $tools
     * @param  list<array<string,mixed>>  $completedActions
     */
    public function validate(
        string $question,
        AgentPlan $plan,
        array $tools,
        array $completedActions,
    ): void {
        if (! $this->intent->matches($question)) {
            return;
        }

        $requiredKind = $this->requiredKind($tools);
        if ($requiredKind === null
            || $this->planCovers($plan, $tools, $requiredKind)
            || $this->historyCovers($completedActions, $tools, $requiredKind)) {
            return;
        }

        throw new AgentPlanValidationException(
            'live_source_coverage_required',
            "The user requested an exhaustive search. Schedule at least one relevant read-only {$requiredKind} tool before answering or declaring insufficient evidence.",
        );
    }

    /** @param array<string,AgentToolDefinition> $tools */
    private function requiredKind(array $tools): ?string
    {
        foreach ($tools as $tool) {
            if ($tool->kind === 'mcp') {
                return 'mcp';
            }
        }

        foreach ($tools as $tool) {
            if ($tool->kind === 'api') {
                return 'api';
            }
        }

        return null;
    }

    /** @param array<string,AgentToolDefinition> $tools */
    private function planCovers(AgentPlan $plan, array $tools, string $requiredKind): bool
    {
        foreach ($plan->actions as $action) {
            if (($tools[$action->tool] ?? null)?->kind === $requiredKind) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $completedActions
     * @param array<string,AgentToolDefinition> $tools
     */
    private function historyCovers(array $completedActions, array $tools, string $requiredKind): bool
    {
        foreach ($completedActions as $action) {
            $toolName = $action['tool'] ?? null;
            $status = $action['status'] ?? null;
            if (is_string($toolName)
                && ($tools[$toolName] ?? null)?->kind === $requiredKind
                && in_array($status, ['completed', 'failed'], true)) {
                return true;
            }
        }

        return false;
    }
}
