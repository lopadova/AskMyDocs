<?php

declare(strict_types=1);

namespace App\Ai;

use App\Agent\AgentExecutionContext;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;

/** One scoped text model covers interpretation, planning, generation and repair. */
final class AgentChatModelScope
{
    private ?AgentExecutionContext $context = null;
    private ?\Carbon\CarbonImmutable $requestDeadline = null;

    /** A shared stage budget includes corrections; it never extends an outer budget. */
    public function withinBudget(float $seconds, callable $callback): mixed
    {
        $previous = $this->requestDeadline;
        $deadline = \Carbon\CarbonImmutable::now()->addMilliseconds((int) floor(max(0, $seconds) * 1000));
        $this->requestDeadline = $previous !== null && $previous->lessThan($deadline) ? $previous : $deadline;
        try {
            return $callback();
        } finally {
            $this->requestDeadline = $previous;
        }
    }

    public function within(AgentExecutionContext $context, callable $callback): mixed
    {
        $previous = $this->context;
        $this->context = $context;
        try {
            return $callback();
        } finally {
            $this->context = $previous;
        }
    }

    public function context(): ?AgentExecutionContext { return $this->context; }

    public function options(array $options): array
    {
        if ($this->requestDeadline !== null) {
            $seconds = (int) floor(now()->diffInSeconds($this->requestDeadline, false));
            if ($seconds < 1) throw new \DomainException('agent_stage_deadline_exhausted');
            $options['timeout'] = min($seconds, max(1, (int) ($options['timeout'] ?? config('agent.synthesis.request_timeout_seconds', 60))));
        }
        if ($this->context === null) {
            return $options;
        }
        if ($this->context->tenantId !== app(TenantContext::class)->current()) {
            throw new \DomainException('agent_model_scope_tenant_mismatch');
        }
        $remaining = app(\App\Agent\AgentRunDeadline::class)->activeRemaining($this->context);
        if ($remaining !== null) {
            $seconds = (int) floor($remaining - max(0, (int) config('agent.finalization.provider_reserve_seconds', 10)));
            if ($seconds < 1) throw new \DomainException('agent_provider_deadline_exhausted');
            $options['timeout'] = min($seconds, max(1, (int) ($options['timeout'] ?? config('agent.synthesis.request_timeout_seconds', 60))));
            $effort = config('agent.synthesis.reasoning_effort', 'low');
            if (in_array($effort, ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], true)) {
                $options['reasoning'] ??= ['effort' => $effort];
            }
        }
        // No catch/default-on-error: a configured economical run must not
        // silently use an expensive stage default when settings cannot be read.
        $model = app(AppSettingsResolver::class)->effective('ai.chat_model', $this->context->tenantId,
            $this->context->projectKey ?? '*');
        if (is_string($model) && trim($model) !== '') {
            $options['model'] = trim($model);
            $routing = app(AppSettingsResolver::class)->effective('ai.chat_routing', $this->context->tenantId,
                $this->context->projectKey ?? '*');
            if ($routing === 'economy') {
                $options['provider'] = ['sort' => 'price', 'require_parameters' => true,
                    'max_price' => config('ai.economy_max_price')];
            }
        }

        return $options;
    }
}
