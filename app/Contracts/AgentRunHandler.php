<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\AgentRun;

interface AgentRunHandler
{
    public function handle(AgentRun $run): void;
}
