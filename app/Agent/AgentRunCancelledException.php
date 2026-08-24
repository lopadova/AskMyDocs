<?php

declare(strict_types=1);

namespace App\Agent;

final class AgentRunCancelledException extends \RuntimeException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct("Agent run [{$runId}] was cancelled.");
    }
}
