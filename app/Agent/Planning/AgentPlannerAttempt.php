<?php

declare(strict_types=1);

namespace App\Agent\Planning;

final readonly class AgentPlannerAttempt
{
    public function __construct(
        public AgentPlan $plan,
        public int $latencyMs,
        public ?int $promptTokens,
        public ?int $completionTokens,
    ) {}
}
