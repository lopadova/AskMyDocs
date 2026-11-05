<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\AgentExecutionContext;
use App\Services\Admin\AppSettingsResolver;

final readonly class AgentPlannerModeResolver
{
    public function __construct(private AppSettingsResolver $settings) {}

    public function forContext(AgentExecutionContext $context): string
    {
        $mode = $this->settings->effective(
            'agent.planner.mode',
            $context->tenantId,
            $context->projectKey ?? '*',
        );

        return is_string($mode) && in_array($mode, ['classic', 'shadow', 'capability'], true)
            ? $mode
            : 'classic';
    }
}
