<?php

declare(strict_types=1);

namespace App\Jobs\Imap;

use App\Connectors\Imap\Backfill\ImapBackfillImporter;
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
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Throwable;

final class ImportImapBackfillWindowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $timeout = 600;
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
        DB::transaction(function () use ($exception): void {
            $window = ImapBackfillWindow::query()
                ->forTenant($this->tenantId)
                ->where('id', $this->windowId)
                ->lockForUpdate()
                ->first();
            if ($window === null || $window->status === ImapBackfillWindow::STATUS_COMPLETED) {
                return;
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

            ImapBackfill::query()
                ->forTenant($this->tenantId)
                ->where('id', $window->imap_backfill_id)
                ->whereIn('status', ImapBackfill::ACTIVE_STATUSES)
                ->update([
                    'status' => ImapBackfill::STATUS_FAILED,
                    'heartbeat_at' => now(),
                    'error_json' => json_encode($error, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        });
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
