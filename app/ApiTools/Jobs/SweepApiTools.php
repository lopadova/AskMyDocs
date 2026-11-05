<?php

declare(strict_types=1);

namespace App\ApiTools\Jobs;

use App\Agent\AgentExecutionContext;
use App\ApiTools\Models\ApiTool;
use App\ApiTools\Models\ApiToolRun;
use App\ApiTools\ToolContinuation;
use App\ApiTools\ToolLifecycle;
use App\ApiTools\ToolRuntime;
use App\ApiTools\ToolVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/** System sweep: enumerate tenants once, then scope every tool/run read explicitly. */
final class SweepApiTools implements ShouldQueueAfterCommit, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue(config('api-tools.queue', 'api-tools'));
        if (config('api-tools.connection')) { $this->onConnection(config('api-tools.connection')); }
    }

    public function handle(ToolLifecycle $lifecycle, ToolVerificationService $verification): void
    {
        if (! config('api-tools.enabled', false)) { return; }
        $host = app(\App\Support\TenantContext::class);
        $connector = app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class);
        $before = [$host->current(), $connector->current(), app()->getLocale(), date_default_timezone_get()];
        try {
            foreach (ApiTool::query()->distinct()->pluck('tenant_id') as $tenant) {
                $host->set($tenant);
                $connector->set($tenant);
                ApiTool::query()->forTenant($tenant)->where('publication_intent', true)->where('manual_disabled', false)
                    ->chunkById(100, function ($tools) use ($lifecycle, $tenant): void {
                        foreach ($tools as $tool) {
                            $context = new AgentExecutionContext((string) Str::uuid(), $tenant, $tool->project_key ?: null, 'chat', 'user', $tool->created_by ? (string) $tool->created_by : null, 'en', 'UTC');
                            $context->activate();
                            $lifecycle->checkDependencies($tool, $context);
                        }
                    });
                ApiToolRun::query()->forTenant($tenant)->whereNull('resumed_at')->chunkById(100, function ($runs) use ($lifecycle, $verification): void {
                    foreach ($runs as $run) {
                        $context = AgentExecutionContext::fromArray($run->context);
                        $context->activate();
                        if (! $run->terminal() && $run->deadline_at->isPast()) {
                            app(ToolRuntime::class)->finish($run->id, 'timed_out', 'api_tool_timeout');
                        } elseif (! $run->terminal()) {
                            app(ToolRuntime::class)->recover($run);
                        } elseif ($run->terminal()) {
                            $lifecycle->recordOutcome($run);
                            $verification->consumeRun($run);
                            // The chunk snapshot can predate attachAgent/attachChat. Decide only
                            // under the same row lock used by those handoffs and reread the row.
                            \Illuminate\Support\Facades\DB::transaction(function () use ($run): void {
                                $current = ApiToolRun::query()->forTenant($run->tenant_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
                                if (! $current->terminal()) { return; }
                                ToolContinuation::dispatchIfReady($current);
                                if (! in_array(data_get($current->continuation, 'type'), ['agent', 'chat'], true)) {
                                    $current->forceFill(['resumed_at'=>now()])->save();
                                }
                            });
                        }
                    }
                });
            }
        } finally { $host->set($before[0]); $connector->set($before[1]); app()->setLocale($before[2]); date_default_timezone_set($before[3]); }
    }
}
