<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\User;
use App\Models\WidgetIdentity;
use App\Models\WidgetKey;
use App\Support\SupportedLocale;
use App\Support\TenantContext;
use Illuminate\Support\Str;

final readonly class AgentExecutionContextFactory
{
    public function __construct(private TenantContext $tenants) {}

    public function forUser(
        User $user,
        ?string $projectKey,
        string $channel = 'chat',
        ?string $runId = null,
    ): AgentExecutionContext {
        return new AgentExecutionContext(
            runId: $runId ?? (string) Str::uuid(),
            tenantId: $this->tenants->current(),
            projectKey: $this->normalizeProject($projectKey),
            channel: $channel,
            actorType: 'user',
            actorId: (string) $user->getKey(),
            locale: SupportedLocale::normalize($user->locale),
            timezone: (string) config('app.timezone', 'UTC'),
        );
    }

    public function forWidget(
        WidgetKey $key,
        ?WidgetIdentity $identity,
        ?string $locale,
        ?string $runId = null,
    ): AgentExecutionContext {
        return new AgentExecutionContext(
            runId: $runId ?? (string) Str::uuid(),
            tenantId: (string) $key->tenant_id,
            projectKey: $this->normalizeProject($key->project_key),
            channel: 'widget',
            actorType: $identity === null ? 'anonymous_widget' : 'widget_identity',
            actorId: $identity === null ? null : (string) $identity->getKey(),
            locale: SupportedLocale::normalize($locale),
            timezone: (string) config('app.timezone', 'UTC'),
        );
    }

    private function normalizeProject(?string $projectKey): ?string
    {
        $projectKey = is_string($projectKey) ? trim($projectKey) : '';

        return $projectKey === '' ? null : $projectKey;
    }
}
