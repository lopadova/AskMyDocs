<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Models\ConnectorSyncRun;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Throwable;

/**
 * v8.21 (Ciclo 2) — records one {@see ConnectorSyncRun} per `ConnectorSyncJob`
 * execution, host-side, off Laravel's queue lifecycle.
 *
 * The package job emits no domain events, so the host OBSERVES it via the
 * generic queue events:
 *   - `JobProcessing` → open a `running` run row + arm {@see SyncRunContext}.
 *   - `JobProcessed`  → close it `success`/`partial` with the discovered count.
 *   - `JobFailed`     → close it `failed` with the error.
 *
 * Recording NEVER breaks the sync path (every write is wrapped in try/catch and
 * logged — same posture as ChatLogManager / HostIngestionBridge::emitAudit).
 * Only `ConnectorSyncJob` payloads are acted on; all other jobs are ignored.
 */
final class ConnectorSyncRunRecorder
{
    public function __construct(private readonly SyncRunContext $context) {}

    public function subscribe(Dispatcher $events): void
    {
        // Bind THIS resolved instance (not the class string) so the listeners
        // run on it directly — unambiguous instance dispatch, and they share the
        // singleton SyncRunContext injected into this recorder.
        $events->listen(JobProcessing::class, [$this, 'onProcessing']);
        $events->listen(JobProcessed::class, [$this, 'onProcessed']);
        $events->listen(JobFailed::class, [$this, 'onFailed']);
    }

    public function onProcessing(JobProcessing $event): void
    {
        $job = $this->resolveSyncJob($event);
        if ($job === null) {
            return;
        }

        try {
            $installation = ConnectorInstallation::query()
                ->where('id', $job->installationId)
                ->where('tenant_id', $job->tenantId)
                ->first();

            $run = ConnectorSyncRun::create([
                'tenant_id' => $job->tenantId,
                'connector_installation_id' => $job->installationId,
                'connector_name' => $installation?->connector_name ?? 'unknown',
                'label' => $installation?->label ?? 'default',
                'queue' => $event->job->getQueue(),
                'status' => ConnectorSyncRun::STATUS_RUNNING,
                'started_at' => now(),
                'items_discovered' => 0,
                'items_failed' => 0,
            ]);

            $this->context->begin($run->id);
        } catch (Throwable $e) {
            $this->context->end();
            $this->logFailure('onProcessing', $job, $e);
        }
    }

    public function onProcessed(JobProcessed $event): void
    {
        $job = $this->resolveSyncJob($event);
        if ($job === null) {
            return;
        }

        // Best-effort (class contract): a DB hiccup here must NOT crash the
        // queue worker — guard the read + close in try/catch, ensuring the run
        // context is always released.
        try {
            // A partial sync leaves error_json.partial_errors on the installation
            // (ConnectorSyncJob::recordSuccess); reflect that as `partial`.
            $installation = ConnectorInstallation::query()
                ->where('id', $job->installationId)
                ->where('tenant_id', $job->tenantId)
                ->first();
            $partialErrors = $installation?->error_json['partial_errors'] ?? null;
            $isPartial = is_array($partialErrors) && $partialErrors !== [];

            $this->close(
                $job,
                $isPartial ? ConnectorSyncRun::STATUS_PARTIAL : ConnectorSyncRun::STATUS_SUCCESS,
                errorJson: $isPartial ? ['partial_errors' => $partialErrors] : null,
                itemsFailed: is_array($partialErrors) ? count($partialErrors) : 0,
            );
        } catch (Throwable $e) {
            $this->context->end();
            $this->logFailure('onProcessed', $job, $e);
        }
    }

    public function onFailed(JobFailed $event): void
    {
        $job = $this->resolveSyncJob($event);
        if ($job === null) {
            return;
        }

        $this->close(
            $job,
            ConnectorSyncRun::STATUS_FAILED,
            errorJson: [
                'message' => $event->exception->getMessage(),
                'class' => $event->exception::class,
            ],
            itemsFailed: 0,
        );
    }

    /**
     * @param  array<string,mixed>|null  $errorJson
     */
    private function close(ConnectorSyncJob $job, string $status, ?array $errorJson, int $itemsFailed): void
    {
        $runId = $this->context->activeRunId();
        $discovered = $this->context->discovered();
        $this->context->end();

        if ($runId === null) {
            return; // onProcessing never opened a row (e.g. it threw) — nothing to close.
        }

        try {
            $run = ConnectorSyncRun::query()
                ->where('id', $runId)
                ->where('tenant_id', $job->tenantId)
                ->first();
            if ($run === null) {
                return;
            }

            $finishedAt = now();
            $run->forceFill([
                'status' => $status,
                'finished_at' => $finishedAt,
                'duration_ms' => $run->started_at !== null
                    ? max(0, (int) $run->started_at->diffInMilliseconds($finishedAt))
                    : null,
                'items_discovered' => $discovered,
                'items_failed' => $itemsFailed,
                'error_json' => $errorJson,
            ])->save();
        } catch (Throwable $e) {
            $this->logFailure('close', $job, $e);
        }
    }

    /**
     * Extract the `ConnectorSyncJob` instance from a queue event, or null when
     * the event is for any other job.
     */
    private function resolveSyncJob(JobProcessing|JobProcessed|JobFailed $event): ?ConnectorSyncJob
    {
        try {
            $payload = $event->job->payload();
            if (($payload['data']['commandName'] ?? null) !== ConnectorSyncJob::class) {
                return null;
            }
            // Restrict allowed classes to prevent PHP object-injection from a
            // tampered queue payload (the job carries only int + string props).
            $command = unserialize(
                $payload['data']['command'],
                ['allowed_classes' => [ConnectorSyncJob::class]],
            );

            return $command instanceof ConnectorSyncJob ? $command : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function logFailure(string $stage, ConnectorSyncJob $job, Throwable $e): void
    {
        Log::warning('ConnectorSyncRunRecorder failed', [
            'stage' => $stage,
            'installation_id' => $job->installationId,
            'tenant_id' => $job->tenantId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
