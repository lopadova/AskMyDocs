<?php

declare(strict_types=1);

namespace App\Realtime;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use App\Models\User;

final readonly class AuthorizeRealtimeChatTurn
{
    public function __construct(private RealtimeSessionAccess $access) {}

    public function authorize(mixed $owner, ToolCall $call, AgentSession $session): bool
    {
        return $owner instanceof User
            && $call->name === 'askmydocs.chat_turn'
            && $this->access->allows($owner, $session);
    }
}
