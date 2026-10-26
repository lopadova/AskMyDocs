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
        if ($this->context === null) {
            return $options;
        }
        if ($this->context->tenantId !== app(TenantContext::class)->current()) {
            throw new \DomainException('agent_model_scope_tenant_mismatch');
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
