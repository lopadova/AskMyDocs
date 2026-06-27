<?php

declare(strict_types=1);

namespace App\Connectors\Scheduling;

use App\Connectors\SerializedConnectorSyncJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Scheduling\SyncScheduler;

/**
 * Host scheduler that routes each due install through
 * {@see SerializedConnectorSyncJob::dispatchFor()} — the per-mailbox re-queue
 * {@see SerializedConnectorSyncJob} for an IMAP account with serialization on, the
 * bare vendor {@see \Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob} otherwise.
 *
 * It MIRRORS the vendor {@see SyncScheduler::dispatchDueSyncs()} sweep verbatim
 * (cadence config + memory-safe chunkById over ACTIVE installations + the same
 * `isDue` window). The only difference is the dispatch call: the vendor sweep
 * hardcodes `ConnectorSyncJob::dispatch()` with no override seam, so swapping the
 * routing requires reimplementing the (tiny) loop here. Wired in `bootstrap/app.php`
 * in place of the vendor scheduler. If the package later exposes a dispatch seam,
 * this collapses to a one-method override.
 */
final class SerializedSyncScheduler extends SyncScheduler
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

    public function dispatchDueSyncs(): int
    {
        // Defence in depth: skip silently if the migration hasn't run yet.
        if (! Schema::hasTable('connector_installations')) {
            return 0;
        }

        $defaultMinutes = (int) config('connectors.default_sync_cadence_minutes', 15);
        $perConnector = (array) config('connectors.per_connector_cadence', []);

        $dispatched = 0;

        // R3 — chunkById, ACTIVE only (PENDING/DISABLED/ERRORED are excluded; the
        // job short-circuits them anyway). Same filter as the vendor sweep.
        ConnectorInstallation::query()
            ->where('status', ConnectorInstallation::STATUS_ACTIVE)
            ->orderBy('id')
            ->chunkById(100, function ($installations) use ($defaultMinutes, $perConnector, &$dispatched): void {
                foreach ($installations as $installation) {
                    $cadenceMinutes = $this->cadenceMinutesFor(
                        $installation->connector_name,
                        $perConnector,
                        $defaultMinutes,
                    );

                    if (! $this->dueForSync($installation, $cadenceMinutes)) {
                        continue;
                    }

                    SerializedConnectorSyncJob::dispatchFor($installation);
                    $dispatched++;
                }
            });

        return $dispatched;
    }

    /**
     * @param  array<string,int>  $perConnector
     */
    private function cadenceMinutesFor(string $connectorName, array $perConnector, int $default): int
    {
        $override = $perConnector[$connectorName] ?? null;
        if (is_int($override) && $override > 0) {
            return $override;
        }

        return $default > 0 ? $default : 15;
    }

    private function dueForSync(ConnectorInstallation $installation, int $cadenceMinutes): bool
    {
        if ($installation->last_sync_at === null) {
            return true; // never synced — eligible immediately.
        }

        return $installation->last_sync_at->copy()->addMinutes($cadenceMinutes)->lte(now());
    }
}
