<?php

declare(strict_types=1);

namespace App\ApiTools\Jobs;

use App\Agent\AgentExecutionContext;
use App\ApiTools\Models\ApiToolRun;
use App\ApiTools\ToolContinuation;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;

final class ResumeApiTool implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 5;
    public int $timeout = 180;

    public function __construct(public readonly string $runId, public readonly string $tenantId)
    {
        $this->onQueue((string) config('api-tools.queue', 'api-tools'));
        if (config('api-tools.connection')) { $this->onConnection(config('api-tools.connection')); }
    }

    public function backoff(): array { return [5, 15, 30, 60]; }

    public function handle(ToolContinuation $continuations): void
    {
        $lock = Cache::lock('api-tool-continuation:'.$this->tenantId.':'.$this->runId, $this->timeout + 30);
        if (! $lock->get()) {
            if ($this->job !== null) { $this->release(5); }
            return;
        }
        $host = app(TenantContext::class);
        $connector = app(ConnectorTenantContext::class);
        $before = [$host->current(), $connector->current(), App::currentLocale(), date_default_timezone_get(), Auth::user()];
        try {
            $run = ApiToolRun::query()->forTenant($this->tenantId)->findOrFail($this->runId);
            if (! $run->terminal() || $run->resumed_at !== null) { return; }
            $context = AgentExecutionContext::fromArray($run->context);
            if ($context->tenantId !== $this->tenantId) { throw new \DomainException('api_tool_context_mismatch'); }
            $context->activate();
            Auth::forgetGuards();
            if ($context->actorType === 'user' && ($user = User::find($context->actorId)) !== null) { Auth::setUser($user); }
            $continuations->resume($run);
        } finally {
            Auth::forgetGuards();
            if ($before[4] !== null) { Auth::setUser($before[4]); }
            $host->set($before[0]);
            $connector->set($before[1]);
            App::setLocale($before[2]);
            date_default_timezone_set($before[3]);
            $lock->release();
        }
    }
}
