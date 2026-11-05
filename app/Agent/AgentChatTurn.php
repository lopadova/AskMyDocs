<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\Message;

final readonly class AgentChatTurn
{
    public function __construct(
        public Message $message,
        public AgentRun $run,
    ) {}
}
