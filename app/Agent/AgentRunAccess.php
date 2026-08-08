<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\User;
use App\Models\WidgetIdentity;
use App\Models\WidgetSession;
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

    public function forWidgetOrFail(
        string $publicRunId,
        WidgetSession $session,
        ?WidgetIdentity $identity,
    ): AgentRun {
        $query = AgentRun::query()
            ->forTenant($this->tenants->current())
            ->where('run_id', $publicRunId)
            ->where('widget_session_id', $session->id)
            ->where('project_key', $session->project_key)
            ->where('channel', 'widget');

        if ($identity instanceof WidgetIdentity) {
            $query
                ->where('widget_identity_id', $identity->id)
                ->where('actor_type', 'widget_identity')
                ->where('actor_id', (string) $identity->id);
        } else {
            $query
                ->whereNull('widget_identity_id')
                ->where('actor_type', 'anonymous_widget')
                ->whereNull('actor_id');
        }

        return $query->firstOrFail();
    }
}
