<?php

declare(strict_types=1);

namespace App\Jobs\Imap;

use App\Connectors\SerializedConnectorSyncJob;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;

final class PumpImapBackfillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int $backfillId,
        public readonly string $tenantId,
    ) {}

    public function handle(): void
    {
        $hostTenant = app(TenantContext::class);
        $packageTenant = app(PackageTenantContext::class);
        $previousHostTenant = $hostTenant->current();
        $previousPackageTenant = $packageTenant->current();

        try {
            $hostTenant->set($this->tenantId);
            $packageTenant->set($this->tenantId);
            $windowId = DB::transaction(function (): ?int {
                $backfill = ImapBackfill::query()
                    ->forTenant($this->tenantId)
                    ->where('id', $this->backfillId)
                    ->lockForUpdate()
                    ->first();
                if ($backfill === null || $backfill->status !== ImapBackfill::STATUS_RUNNING) {
                    return null;
                }

                $staleBefore = now()->subMinutes(max(11, (int) config('connectors.imap.backfill.stale_after_minutes', 20)));
                ImapBackfillWindow::query()
                    ->forTenant($this->tenantId)
                    ->where('imap_backfill_id', $backfill->id)
                    ->whereIn('status', [ImapBackfillWindow::STATUS_QUEUED, ImapBackfillWindow::STATUS_RUNNING])
                    ->where(fn ($query) => $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', $staleBefore))
                    ->update([
                        'status' => ImapBackfillWindow::STATUS_PENDING,
                        'next_attempt_at' => now(),
                        'updated_at' => now(),
                    ]);

                $activeExists = ImapBackfillWindow::query()
                    ->forTenant($this->tenantId)
                    ->where('imap_backfill_id', $backfill->id)
                    ->whereIn('status', [ImapBackfillWindow::STATUS_QUEUED, ImapBackfillWindow::STATUS_RUNNING])
                    ->exists();
                if ($activeExists) {
                    return null;
                }

                $window = ImapBackfillWindow::query()
                    ->forTenant($this->tenantId)
                    ->where('imap_backfill_id', $backfill->id)
                    ->where('status', ImapBackfillWindow::STATUS_PENDING)
                    ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                    ->orderBy('window_start')
                    ->orderBy('mailbox')
                    ->lockForUpdate()
                    ->first();

                if ($window !== null) {
                    $window->forceFill([
                        'status' => ImapBackfillWindow::STATUS_QUEUED,
                        'heartbeat_at' => now(),
                        'error_json' => null,
                    ])->save();
                    $backfill->forceFill(['heartbeat_at' => now()])->save();

                    return $window->id;
                }

                $pendingExists = ImapBackfillWindow::query()
                    ->forTenant($this->tenantId)
                    ->where('imap_backfill_id', $backfill->id)
                    ->where('status', ImapBackfillWindow::STATUS_PENDING)
                    ->exists();
                if ($pendingExists) {
                    return null; // delayed retry; the scheduler will pump it when due.
                }

                $completed = ImapBackfillWindow::query()
                    ->forTenant($this->tenantId)
                    ->where('imap_backfill_id', $backfill->id)
                    ->where('status', ImapBackfillWindow::STATUS_COMPLETED)
                    ->count();
                if ($completed === (int) $backfill->total_windows) {
                    $backfill->forceFill([
                        'status' => ImapBackfill::STATUS_COMPLETED,
                        'completed_windows' => $completed,
                        'completed_at' => now(),
                        'heartbeat_at' => now(),
                        'error_json' => null,
                    ])->save();

                    $installation = ConnectorInstallation::query()
                        ->forTenant($this->tenantId)
                        ->where('id', $backfill->connector_installation_id)
                        ->first();
                    if ($installation !== null) {
                        $installation->forceFill(['last_sync_at' => $backfill->cutoff_at])->save();
                        DB::afterCommit(static fn () => SerializedConnectorSyncJob::dispatchFor($installation));
                    }
                }

                return null;
            });

            if ($windowId !== null) {
                ImportImapBackfillWindowJob::dispatch($windowId, $this->tenantId)
                    ->onQueue((string) config('connectors.imap.backfill.queue', 'connectors'));
            }
        } finally {
            $hostTenant->set($previousHostTenant);
            $packageTenant->set($previousPackageTenant);
        }
    }
}
