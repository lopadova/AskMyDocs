<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\User;
use App\Support\TenantContext;

final readonly class AgentRunAccess
{
    public function __construct(private TenantContext $tenants) {}

    public function forUserOrFail(string $publicRunId, User $user): AgentRun
    {
        return AgentRun::query()
            ->forTenant($this->tenants->current())
            ->where('run_id', $publicRunId)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('actor_type', 'user')
            ->where('actor_id', (string) $user->getAuthIdentifier())
            ->where('channel', 'chat')
            ->firstOrFail();
    }
}
