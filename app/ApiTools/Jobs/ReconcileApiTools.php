<?php

declare(strict_types=1);

namespace App\ApiTools\Jobs;

use App\Agent\AgentExecutionContext;
use App\ApiTools\Models\ApiTool;
use App\ApiTools\ToolLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class ReconcileApiTools implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly array $context)
    {
        $this->onQueue(config('api-tools.queue', 'api-tools'));
        if (config('api-tools.connection')) { $this->onConnection(config('api-tools.connection')); }
    }

    public function handle(ToolLifecycle $lifecycle): void
    {
        $host = app(\App\Support\TenantContext::class);
        $connector = app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class);
        $before = [$host->current(), $connector->current(), app()->getLocale(), date_default_timezone_get()];
        try {
            $context = AgentExecutionContext::fromArray($this->context);
            $context->activate();
            ApiTool::query()->forTenant($context->tenantId)->where('project_key', $context->projectKey ?? '')
                ->where('publication_intent', true)->where('manual_disabled', false)->chunkById(100, function ($tools) use ($lifecycle, $context): void {
                    foreach ($tools as $tool) { $lifecycle->checkDependencies($tool, $context); }
                });
        } finally { $host->set($before[0]); $connector->set($before[1]); app()->setLocale($before[2]); date_default_timezone_set($before[3]); }
    }
}
