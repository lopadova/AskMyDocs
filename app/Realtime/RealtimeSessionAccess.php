<?php

declare(strict_types=1);

namespace App\Realtime;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use App\Models\Conversation;
use App\Models\RealtimeAgentSessionLink;
use App\Models\User;
use App\Support\TenantContext;

final readonly class RealtimeSessionAccess
{
    public function __construct(private TenantContext $tenants) {}

    public function linkFor(User $user, AgentSession $session): ?RealtimeAgentSessionLink
    {
        if (! $session->owner instanceof User
            || (string) $session->owner->getKey() !== (string) $user->getAuthIdentifier()
            || $session->state()->status() !== 'active') {
            return null;
        }

        $tenantId = $this->tenants->current();
        $link = RealtimeAgentSessionLink::query()
            ->forTenant($tenantId)
            ->whereKey($session->id)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('expires_at', '>', now())
            ->whereNotNull('conversation_id')
            ->first();

        if (! $link instanceof RealtimeAgentSessionLink) {
            return null;
        }

        $conversationExists = Conversation::query()
            ->forTenant($tenantId)
            ->whereKey($link->conversation_id)
            ->where('user_id', $user->getAuthIdentifier())
            ->exists();

        return $conversationExists ? $link : null;
    }

    public function allows(User $user, AgentSession $session): bool
    {
        return $this->linkFor($user, $session) !== null;
    }
}
