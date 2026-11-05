<?php

declare(strict_types=1);

namespace App\ApiTools\Flow;

use App\ApiTools\Models\ApiToolRun;
use App\Agent\AgentExecutionContext;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\Executor\Jobs\NodeJob;
use Padosoft\LaravelFlow\Executor\Jobs\ResumeTimerJob;

/** Bus pipeline middleware also runs around deserialized vendor queue jobs. */
final readonly class ToolTenantScope
{
    public function handle(object $command, Closure $next): mixed
    {
        if (! $command instanceof CoordinatorJob && ! $command instanceof NodeJob && ! $command instanceof ResumeTimerJob) {
            return $next($command);
        }
        if (isset($command->definitionName) && $command->definitionName !== 'askmydocs.api_tool') { return $next($command); }
        $flow = DB::connection(config('laravel-flow.default_storage'))->table('flow_runs')->where('id', $command->runId)->first(['tenant_id', 'definition_name']);
        if (($flow !== null && $flow->definition_name !== 'askmydocs.api_tool') || ($flow === null && ! isset($command->definitionName))) { return $next($command); }
        $tenant = $flow?->tenant_id;
        if (! is_string($tenant) || $tenant === '') {
            throw new \DomainException('flow_tenant_unavailable');
        }
        $host = app(TenantContext::class);
        $connector = app(ConnectorTenantContext::class);
        $before = [$host->current(), $connector->current(), App::currentLocale(), date_default_timezone_get(), Auth::user(), config('queue.default')];
        try {
            $host->set($tenant);
            $connector->set($tenant);
            Auth::forgetGuards();
            $run = ApiToolRun::query()->forTenant($tenant)->where('flow_run_id', $command->runId)->first();
            if ($run !== null) {
                if (config('api-tools.connection')) { config(['queue.default' => config('api-tools.connection')]); }
                $context = AgentExecutionContext::fromArray($run->context);
                if ($context->tenantId !== $tenant) {
                    throw new \DomainException('api_tool_context_mismatch');
                }
                $context->activate();
                if ($context->actorType === 'user' && ($user = User::find($context->actorId)) !== null) {
                    Auth::setUser($user);
                }
            }
            return $next($command);
        } finally {
            Auth::forgetGuards();
            if ($before[4] !== null) { Auth::setUser($before[4]); }
            $host->set($before[0]);
            $connector->set($before[1]);
            App::setLocale($before[2]);
            date_default_timezone_set($before[3]);
            config(['queue.default' => $before[5]]);
        }
    }
}
