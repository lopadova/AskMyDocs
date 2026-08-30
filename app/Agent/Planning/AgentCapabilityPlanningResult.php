<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\Capabilities\AgentCapabilityRoute;

final readonly class AgentCapabilityPlanningResult
{
    public function __construct(
        public AgentPlan $plan,
        public AgentCapabilityRoute $route,
        public int $plannerLatencyMs,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public int $corrections,
    ) {}
}
