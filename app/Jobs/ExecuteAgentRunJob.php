<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\AgentExecutionContext;
use App\Contracts\AgentRunHandler;
use App\Models\AgentRun;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

final class ExecuteAgentRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public readonly int $agentRunId)
    {
        $this->onQueue((string) config('agent.queue', 'agent'));
    }

    public function handle(AgentRunHandler $handler, TenantContext $tenants): void
    {
        $run = AgentRun::query()->findOrFail($this->agentRunId);
        $tenants->set($run->tenant_id);
        $this->context($run)->activate();
        Auth::forgetGuards();
        if ($run->user !== null) {
            Auth::setUser($run->user);
        }

        try {
            $handler->handle($run);
        } finally {
            // Queue workers are long-lived. Never leak one run's principal
            // into the next job (especially an anonymous widget run).
            Auth::forgetGuards();
        }
    }

    private function context(AgentRun $run): AgentExecutionContext
    {
        return AgentExecutionContext::fromArray([
            'run_id' => $run->run_id,
            'tenant_id' => $run->tenant_id,
            'project_key' => $run->project_key,
            'channel' => $run->channel,
            'actor_type' => $run->actor_type,
            'actor_id' => $run->actor_id,
            'locale' => $run->locale,
            'timezone' => $run->timezone,
        ]);
    }
}
