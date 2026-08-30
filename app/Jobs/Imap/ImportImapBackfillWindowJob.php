<?php

declare(strict_types=1);

namespace App\Jobs\Imap;

use App\Connectors\Imap\Backfill\ImapBackfillImporter;
use App\Connectors\Imap\MailboxBusyException;
use App\Connectors\Imap\MailboxLockKey;
use App\Connectors\SerializedConnectorSyncJob;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use App\Support\TenantContext;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Throwable;

final class ImportImapBackfillWindowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    // Flex workers have a 90-second hard runtime ceiling. Import jobs are kept
    // deliberately below it; the importer also caps each durable UID batch.
    public int $timeout = 75;
    public int $maxExceptions = 5;
    public array $backoff = [30, 60, 120, 300];

    public function __construct(
        public readonly int $windowId,
        public readonly string $tenantId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(max(
            1,
            (int) config('connectors.imap.mailbox_lock.requeue_window_minutes', 30),
        ));
    }

    public function handle(ImapBackfillImporter $importer): void
    {
        $this->runInTenant(fn () => $this->importWindow($importer));
    }

    private function importWindow(ImapBackfillImporter $importer): void
    {
        $window = ImapBackfillWindow::query()
            ->forTenant($this->tenantId)
            ->where('id', $this->windowId)
            ->first();
        if ($window === null || ! in_array($window->status, [
            ImapBackfillWindow::STATUS_QUEUED,
            ImapBackfillWindow::STATUS_RUNNING,
        ], true)) {
            return;
        }
        $backfill = ImapBackfill::query()
            ->forTenant($this->tenantId)
            ->where('id', $window->imap_backfill_id)
            ->where('status', ImapBackfill::STATUS_RUNNING)
            ->first();
        if ($backfill === null) {
            return;
        }
        $installation = ConnectorInstallation::query()
            ->forTenant($this->tenantId)
            ->where('id', $window->connector_installation_id)
            ->firstOrFail();

        $window->forceFill([
            'status' => ImapBackfillWindow::STATUS_RUNNING,
            'attempts' => $window->attempts + 1,
            'started_at' => $window->started_at ?? now(),
            'heartbeat_at' => now(),
        ])->save();

        try {
            $result = $importer->importBatch($installation, $backfill, $window);
        } catch (MailboxBusyException $e) {
            $error = ['message' => $e->getMessage(), 'type' => $e::class, 'at' => now()->toIso8601String()];
            $window->forceFill(['heartbeat_at' => now(), 'error_json' => $error])->save();
            $backfill->forceFill(['heartbeat_at' => now(), 'error_json' => $error])->save();

            $delay = max(1, (int) config('connectors.imap.mailbox_lock.requeue_after_seconds', 60));
            Log::info('[imap-backfill-diag] mailbox busy — re-queuing window instead of failing', [
                'backfill_id' => $backfill->id,
                'window_id' => $window->id,
                'installation_id' => $installation->id,
                'tenant_id' => $this->tenantId,
                'delay_seconds' => $delay,
            ]);
            $this->release($delay);

            return;
        } catch (Throwable $e) {
            $error = ['message' => $e->getMessage(), 'type' => $e::class, 'at' => now()->toIso8601String()];
            $window->forceFill(['heartbeat_at' => now(), 'error_json' => $error])->save();
            $backfill->forceFill(['heartbeat_at' => now(), 'error_json' => $error])->save();
            throw $e;
        }

        DB::transaction(function () use ($result): void {
            $window = ImapBackfillWindow::query()
                ->forTenant($this->tenantId)
                ->where('id', $this->windowId)
                ->lockForUpdate()
                ->firstOrFail();
            $backfill = ImapBackfill::query()
                ->forTenant($this->tenantId)
                ->where('id', $window->imap_backfill_id)
                ->lockForUpdate()
                ->firstOrFail();

            $completed = ! $result->hasMore;
            $window->forceFill([
                'status' => $completed ? ImapBackfillWindow::STATUS_COMPLETED : ImapBackfillWindow::STATUS_PENDING,
                'last_uid' => $result->lastUid,
                'expected_messages' => max($window->expected_messages, $result->expectedMessages),
                'processed_messages' => $window->processed_messages + $result->processedMessages,
                'dispatched_documents' => $window->dispatched_documents + $result->dispatchedDocuments,
                'finished_at' => $completed ? now() : null,
                'heartbeat_at' => now(),
                'next_attempt_at' => $completed ? null : now(),
                'error_json' => null,
            ])->save();

            $backfill->forceFill([
                'processed_messages' => $backfill->processed_messages + $result->processedMessages,
                'dispatched_documents' => $backfill->dispatched_documents + $result->dispatchedDocuments,
                'completed_windows' => $backfill->completed_windows + ($completed ? 1 : 0),
                'heartbeat_at' => now(),
                'error_json' => null,
            ])->save();
        });

        PumpImapBackfillJob::dispatch($backfill->id, $this->tenantId)
            ->onQueue((string) config('connectors.imap.backfill.queue', 'connectors'));
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        $window = ImapBackfillWindow::query()->forTenant($this->tenantId)->find($this->windowId);
        $installation = $window === null ? null : ConnectorInstallation::query()
            ->forTenant($this->tenantId)
            ->find($window->connector_installation_id);
        if ($installation === null || ! SerializedConnectorSyncJob::serializes($installation)) {
            return [];
        }
        $key = MailboxLockKey::forInstallation($installation);

        return $key === null ? [] : [
            (new WithoutOverlapping($key))
                ->shared()
                ->releaseAfter((int) config('connectors.imap.mailbox_lock.requeue_after_seconds', 60))
                ->expireAfter((int) config('connectors.imap.mailbox_lock.ttl_seconds', 700)),
        ];
    }

    public function failed(?Throwable $exception): void
    {
        $this->runInTenant(fn () => $this->markFailed($exception));
    }

    private function markFailed(?Throwable $exception): void
    {
        $backfillId = DB::transaction(function () use ($exception): ?int {
            $window = ImapBackfillWindow::query()
                ->forTenant($this->tenantId)
                ->where('id', $this->windowId)
                ->lockForUpdate()
                ->first();
            if ($window === null || $window->status === ImapBackfillWindow::STATUS_COMPLETED) {
                return null;
            }

            $error = [
                'message' => $exception?->getMessage() ?? 'IMAP batch failed after exhausting retries',
                'type' => $exception === null ? null : $exception::class,
                'at' => now()->toIso8601String(),
            ];
            $window->forceFill([
                'status' => ImapBackfillWindow::STATUS_FAILED,
                'heartbeat_at' => now(),
                'finished_at' => now(),
                'next_attempt_at' => null,
                'error_json' => $error,
            ])->save();

            $backfill = ImapBackfill::query()
                ->forTenant($this->tenantId)
                ->where('id', $window->imap_backfill_id)
                ->whereIn('status', ImapBackfill::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->first();
            if ($backfill === null) {
                return null;
            }

            // A terminal job failure belongs to this durable window. Keep the
            // campaign active so the pump can skip it and claim the remaining
            // pending windows. Once every window is terminal, the pump settles
            // the campaign as failed if any window failed.
            $backfill->forceFill([
                'heartbeat_at' => now(),
                'error_json' => $error,
            ])->save();

            return $backfill->id;
        });

        if ($backfillId !== null) {
            PumpImapBackfillJob::dispatch($backfillId, $this->tenantId)
                ->onQueue((string) config('connectors.imap.backfill.queue', 'connectors'));
        }
    }

    private function runInTenant(callable $callback): mixed
    {
        $hostTenant = app(TenantContext::class);
        $packageTenant = app(PackageTenantContext::class);
        $previousHostTenant = $hostTenant->current();
        $previousPackageTenant = $packageTenant->current();

        try {
            $hostTenant->set($this->tenantId);
            $packageTenant->set($this->tenantId);

            return $callback();
        } finally {
            $hostTenant->set($previousHostTenant);
            $packageTenant->set($previousPackageTenant);
        }
    }
}
