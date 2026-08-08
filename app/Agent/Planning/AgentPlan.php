<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\AgentProgress;
use JsonSerializable;

final readonly class AgentPlan implements JsonSerializable
{
    /** @param list<AgentPlannedAction> $actions */
    public function __construct(public string $decision, public array $actions, public AgentProgress $estimate) {}

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
        ];
    }
}
