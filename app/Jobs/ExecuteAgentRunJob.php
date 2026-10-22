<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentEventPublisher;
use App\Contracts\AgentRunHandler;
use App\Models\AgentRun;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;

final class ExecuteAgentRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $agentRunId,
        public readonly string $tenantId,
        public readonly ?string $diagnosticQueueSpanId = null,
    ) {
        $this->timeout = max(30, (int) config('agent.job_timeout_seconds', 120));
        $this->onQueue((string) config('agent.queue', 'agent'));
    }

    public function handle(
        AgentRunHandler $handler,
        TenantContext $tenants,
        PackageTenantContext $packageTenants,
    ): void
    {
        $run = AgentRun::query()
            ->forTenant($this->tenantId)
            ->findOrFail($this->agentRunId);
        $previousLocale = App::currentLocale();
        $previousTimezone = date_default_timezone_get();
        $diagnostics = app(\App\Agent\Debug\RunDiagnostics::class);
        $diagnostics->end($run, $this->diagnosticQueueSpanId ?? null, metadata: ['operation' => 'worker_received']);
        $span = $diagnostics->begin($run, 'worker.execute', [
            'runtime' => $diagnostics->runtime(),
            'attempt' => $this->attempts(),
            'timeout_seconds' => $this->timeout,
        ]);

        try {
            $tenants->set($this->tenantId);
            $context = $this->context($run);
            $context->assertMatches($run);
            $context->activate();
            Auth::forgetGuards();
            if ($run->user !== null) {
                Auth::setUser($run->user);
            }

            $handler->handle($run);
            $diagnostics->end($run, $span);
        } catch (\Throwable $exception) {
            $diagnostics->failure($run, $span, $exception);
            throw $exception;
        } finally {
            // Queue workers are long-lived. Never leak one run's principal
            // into the next job (especially an anonymous widget run).
            Auth::forgetGuards();
            $tenants->reset();
            $packageTenants->reset();
            App::setLocale($previousLocale);
            date_default_timezone_set($previousTimezone);
        }
    }

    /** Includes queue-level failures/timeouts which bypass the handler catch. */
    public function failed(?\Throwable $exception): void
    {
        try {
            $run = AgentRun::query()->forTenant($this->tenantId)->find($this->agentRunId);
        } catch (\Throwable) {
            return;
        }
        if ($run === null) {
            return;
        }

        try {
            $diagnostics = app(\App\Agent\Debug\RunDiagnostics::class);
            $span = $diagnostics->begin($run, 'worker.failed', ['error_handled' => false]);
            if ($exception !== null) {
                $diagnostics->failure($run, $span, $exception);
            } else {
                $diagnostics->end($run, $span, 'failed', ['operation' => 'queue_failed']);
            }
        } catch (\Throwable) {
            // Missing diagnostics must not prevent durable run finalization.
        }

        try {
            DB::transaction(function () use ($exception): void {
                $run = AgentRun::query()->forTenant($this->tenantId)
                    ->whereKey($this->agentRunId)->lockForUpdate()->first();
                // A duplicate delivery may fail after another worker completed
                // the run or persisted a valid interaction handoff.
                if ($run === null || ! in_array($run->status, [AgentRun::STATUS_QUEUED, AgentRun::STATUS_RUNNING], true)) {
                    return;
                }
                $errorCode = $exception !== null && preg_match('/timeout|timedout/i', $exception::class) === 1
                    ? 'worker_timeout' : 'agent_run_failed';
                $run->forceFill([
                    'status' => AgentRun::STATUS_FAILED,
                    'error_code' => $errorCode,
                    'completed_at' => now(),
                ])->save();
                app(AgentEventPublisher::class)->publish($run, 'run.failed', 'run.failed',
                    data: ['error_code' => $errorCode], canCancel: false);
            });
        } catch (\Throwable) {
            // Queue failure reporting must not replace the original failure.
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
