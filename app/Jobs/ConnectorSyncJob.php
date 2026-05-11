<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Connectors\ConnectorRegistry;
use App\Connectors\SyncResult;
use App\Models\ConnectorInstallation;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * v4.5/W1 — Queued worker that runs a single connector incremental
 * sync.
 *
 * Dispatched by:
 *   - {@see \App\Connectors\Scheduling\SyncScheduler} on cadence
 *     (default: every 15 min per installation, configurable per
 *     connector in `config/connectors.php`).
 *   - {@see \App\Http\Controllers\Api\Admin\ConnectorAdminController::syncNow()}
 *     for an operator-triggered manual sync.
 *
 * Retry semantics: `$tries=3`, `$backoff=[60, 300, 900]` — three
 * attempts with 1min / 5min / 15min spacing. After the third failure
 * the job lands in `failed_jobs`; the installation row is also
 * marked `errored` with the exception text persisted to
 * `error_json` so the admin UI can surface it.
 *
 * Tenant binding: `$tenantId` is captured at DISPATCH time (the
 * scheduler reads each installation's tenant_id then sets it onto
 * the job constructor). On `handle()` the job rebinds the
 * {@see TenantContext} singleton inside the worker process so every
 * downstream query is correctly scoped.
 */
class ConnectorSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $installationId,
        public readonly string $tenantId = 'default',
    ) {
        $this->onQueue(config('connectors.sync_job_queue', 'default'));
    }

    public function handle(ConnectorRegistry $registry, TenantContext $tenantContext): void
    {
        $tenantContext->set($this->tenantId);

        $installation = ConnectorInstallation::query()
            ->where('id', $this->installationId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if ($installation === null) {
            // Installation was deleted between dispatch + handle —
            // silent no-op + log breadcrumb. Not an error condition.
            Log::info('ConnectorSyncJob: installation no longer exists, skipping.', [
                'installation_id' => $this->installationId,
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        if ($installation->status === ConnectorInstallation::STATUS_DISABLED) {
            // Operator disabled the connector since dispatch — skip.
            return;
        }

        $connector = $registry->get($installation->connector_name);
        if ($connector === null) {
            // Connector was de-registered (package uninstalled). Mark
            // the installation errored so the admin UI surfaces it.
            $installation->forceFill([
                'status' => ConnectorInstallation::STATUS_ERRORED,
                'error_json' => [
                    'message' => "Connector '{$installation->connector_name}' is no longer registered.",
                    'recorded_at' => now()->toIso8601String(),
                ],
            ])->save();

            return;
        }

        $since = $installation->last_sync_at;

        try {
            $result = $connector->syncIncremental($this->installationId, $since);
            $this->recordSuccess($installation, $result);
        } catch (\Throwable $e) {
            $this->recordFailure($installation, $e);
            throw $e;
        }
    }

    private function recordSuccess(ConnectorInstallation $installation, SyncResult $result): void
    {
        $installation->forceFill([
            'last_sync_at' => $result->completedAt,
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'error_json' => $result->hasErrors()
                ? ['partial_errors' => $result->errors, 'recorded_at' => now()->toIso8601String()]
                : null,
        ])->save();
    }

    private function recordFailure(ConnectorInstallation $installation, \Throwable $e): void
    {
        Log::error('ConnectorSyncJob failed', [
            'installation_id' => $this->installationId,
            'tenant_id' => $this->tenantId,
            'connector' => $installation->connector_name,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $installation->forceFill([
            'status' => ConnectorInstallation::STATUS_ERRORED,
            'error_json' => [
                'message' => $e->getMessage(),
                'class' => $e::class,
                'recorded_at' => now()->toIso8601String(),
            ],
        ])->save();
    }
}
