<?php

declare(strict_types=1);

namespace App\Connectors\Scheduling;

use App\Jobs\ConnectorSyncJob;
use App\Models\ConnectorInstallation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

/**
 * v4.5/W1 — Connector sync scheduler wire-up.
 *
 * Bridges the scheduler hook in `bootstrap/app.php` to the
 * per-installation cadence configured in `config/connectors.php`.
 *
 * Strategy: one scheduled closure per minute that walks every
 * non-disabled installation and dispatches a {@see ConnectorSyncJob}
 * when the cadence window has elapsed since `last_sync_at`. We
 * intentionally do NOT register one Laravel-schedule entry per
 * installation — installations come and go at runtime, and the
 * scheduler config is loaded ONCE at boot, so a dynamic per-row
 * registration would silently fail to pick up new installations until
 * the next deploy.
 *
 * Cadence:
 *   - Default: `connectors.default_sync_cadence_minutes` (15 min)
 *   - Per-connector override: `connectors.per_connector_cadence.{key}`
 *
 * `withoutOverlapping()` is enforced on the scheduler-level closure
 * AND each job runs `onQueue($queue)` so workers don't pile up.
 * The job itself is the natural lock unit (one queued job per
 * installation per dispatch).
 */
class SyncScheduler
{
    public function registerSchedules(Schedule $schedule): void
    {
        $schedule->call(function (): void {
            $this->dispatchDueSyncs();
        })
            ->everyMinute()
            ->name('connectors.dispatch-due-syncs')
            ->onOneServer()
            ->withoutOverlapping();
    }

    /**
     * Walk the active installations and dispatch a sync job for any
     * whose cadence window has elapsed.
     */
    public function dispatchDueSyncs(): int
    {
        // Defence in depth: if the migration hasn't run yet (fresh
        // checkout / partial deploy), skip silently rather than
        // throwing the scheduler closure.
        if (! Schema::hasTable('connector_installations')) {
            return 0;
        }

        $defaultMinutes = (int) config('connectors.default_sync_cadence_minutes', 15);
        $perConnector = (array) config('connectors.per_connector_cadence', []);

        $dispatched = 0;

        // R3 — chunkById so the sweep is memory-safe even on hosts
        // with hundreds of installations across many tenants.
        ConnectorInstallation::query()
            ->whereIn('status', [
                ConnectorInstallation::STATUS_ACTIVE,
                ConnectorInstallation::STATUS_PENDING,
            ])
            ->orderBy('id')
            ->chunkById(100, function ($installations) use ($defaultMinutes, $perConnector, &$dispatched): void {
                foreach ($installations as $installation) {
                    $cadenceMinutes = $this->resolveCadenceMinutes(
                        $installation->connector_name,
                        $perConnector,
                        $defaultMinutes,
                    );

                    if (! $this->isDue($installation, $cadenceMinutes)) {
                        continue;
                    }

                    ConnectorSyncJob::dispatch($installation->id, $installation->tenant_id);
                    $dispatched++;
                }
            });

        return $dispatched;
    }

    /**
     * @param  array<string,int>  $perConnector
     */
    private function resolveCadenceMinutes(string $connectorName, array $perConnector, int $default): int
    {
        $override = $perConnector[$connectorName] ?? null;
        if (is_int($override) && $override > 0) {
            return $override;
        }

        return $default > 0 ? $default : 15;
    }

    private function isDue(ConnectorInstallation $installation, int $cadenceMinutes): bool
    {
        if ($installation->last_sync_at === null) {
            // Never synced — eligible immediately. The job itself
            // decides whether to fall back to a full sync (since=null)
            // or run incremental.
            return true;
        }

        $nextEligibleAt = $installation->last_sync_at->copy()->addMinutes($cadenceMinutes);

        return $nextEligibleAt->lte(now());
    }
}
