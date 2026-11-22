<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\AgentProgress;
use JsonSerializable;

final readonly class AgentPlan implements JsonSerializable
{
    /** @param list<AgentPlannedAction> $actions @param list<AgentPlannedAction> $rejectedActions */
    public function __construct(public string $decision, public array $actions, public AgentProgress $estimate,
        public array $rejectedActions = [], public array $rejectionCodes = []) {}

    public function shouldCallTools(): bool
    {
        return $this->decision === 'tools' && $this->actions !== [];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'decision' => $this->decision,
            'actions' => array_map(static fn (AgentPlannedAction $action): array => $action->jsonSerialize(), $this->actions),
            'estimate' => $this->estimate->jsonSerialize(),
            ...$this->rejectedActions === [] ? [] : ['rejected_actions' => array_map(
                fn (AgentPlannedAction $action): array => [...$action->jsonSerialize(), 'error_code' => $this->rejectionCode($action)], $this->rejectedActions,
            )],
        ];
    }

    public function rejectionCode(AgentPlannedAction $action): string
    {
        return $this->rejectionCodes[$action->id] ?? 'input_pattern_branch_rejected';
    }
}
