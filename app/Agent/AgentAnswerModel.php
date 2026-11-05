<?php

declare(strict_types=1);

namespace App\Agent;

use App\Services\Admin\AppSettingsResolver;

/** Stage-specific defaults remain intact outside the configured tenant/project. */
final readonly class AgentAnswerModel
{
    /** @param array<string,string|null> $runtimeOverrides Explicit local-probe choices; never sourced from documents. */
    public function __construct(private AppSettingsResolver $settings, private array $runtimeOverrides = []) {}

    public function synthesis(AgentExecutionContext $context, bool $repair = false): ?string
    {
        if ($repair) {
            $model = $this->selected('agent.synthesis.repair_model', $context);
            if ($model !== null) {
                return $model;
            }
        }

        return $this->selected('agent.synthesis.model', $context);
    }

    public function presentation(AgentExecutionContext $context): ?string
    {
        $model = config('agent.presentation.model');

        return is_string($model) && trim($model) !== '' ? trim($model) : $this->synthesis($context);
    }

    private function selected(string $key, AgentExecutionContext $context): ?string
    {
        $model = array_key_exists($key, $this->runtimeOverrides)
            ? $this->runtimeOverrides[$key]
            : $this->settings->effective($key, $context->tenantId, $context->projectKey ?? '*');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }
}
