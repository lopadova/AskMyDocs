<?php

declare(strict_types=1);

namespace App\Jobs\Imap;

use App\Connectors\Imap\Backfill\ImapBackfillDiscovery;
use App\Connectors\Imap\MailboxLockKey;
use App\Connectors\SerializedConnectorSyncJob;
use App\Jobs\Middleware\TraceImapBackfillDiscovery;
use App\Models\ImapBackfill;
use App\Support\TenantContext;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Throwable;

final class DiscoverImapBackfillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $timeout = 600;
    public array $backoff = [30, 60, 120, 300];
    public bool $diagnosticHandleEntered = false;

    public function __construct(
        public readonly int $backfillId,
        public readonly string $tenantId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(max(
            1,
            (int) config('connectors.imap.mailbox_lock.requeue_window_minutes', 30),
        ));
    }

    public function handle(ImapBackfillDiscovery $discovery): void
    {
        $this->diagnosticHandleEntered = true;
        Log::info('[imap-backfill-diag] discovery handle entered', [
            'backfill_id' => $this->backfillId,
            'tenant_id' => $this->tenantId,
            'queue_attempt' => $this->attempts(),
        ]);
        $this->runInTenant(fn () => $this->discover($discovery));
    }

    private function discover(ImapBackfillDiscovery $discovery): void
    {
        $backfill = ImapBackfill::query()->forTenant($this->tenantId)->find($this->backfillId);
        if ($backfill === null || $backfill->status !== ImapBackfill::STATUS_DISCOVERING) {
            return;
        }
        $installation = ConnectorInstallation::query()
            ->forTenant($this->tenantId)
            ->where('id', $backfill->connector_installation_id)
            ->where('connector_name', 'imap')
            ->firstOrFail();

        try {
            $discovery->discover($installation, $backfill);
        } catch (Throwable $e) {
            $backfill->forceFill([
                'heartbeat_at' => now(),
                'error_json' => ['message' => $e->getMessage(), 'type' => $e::class],
            ])->save();
            Log::error('[imap-backfill-diag] discovery handle preserved original failure', [
                'backfill_id' => $this->backfillId,
                'tenant_id' => $this->tenantId,
                'queue_attempt' => $this->attempts(),
                'exception' => $e,
            ]);
            throw $e;
        }

        PumpImapBackfillJob::dispatch($backfill->id, $this->tenantId)
            ->onQueue((string) config('connectors.imap.backfill.queue', 'connectors'));
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        $releaseAfter = (int) config('connectors.imap.mailbox_lock.requeue_after_seconds', 60);
        $ttl = (int) config('connectors.imap.mailbox_lock.ttl_seconds', 700);
        $backfill = ImapBackfill::query()->forTenant($this->tenantId)->find($this->backfillId);
        $installation = $backfill === null ? null : ConnectorInstallation::query()
            ->forTenant($this->tenantId)
            ->find($backfill->connector_installation_id);
        if ($installation === null || ! SerializedConnectorSyncJob::serializes($installation)) {
            return [new TraceImapBackfillDiscovery(null, $releaseAfter, $ttl)];
        }
        $key = MailboxLockKey::forInstallation($installation);

        $middleware = [new TraceImapBackfillDiscovery($key, $releaseAfter, $ttl)];
        if ($key !== null) {
            $middleware[] = (new WithoutOverlapping($key))
                ->releaseAfter($releaseAfter)
                ->expireAfter($ttl);
        }

        return $middleware;
    }

    public function failed(?Throwable $exception): void
    {
        $this->runInTenant(fn () => $this->markFailed($exception));
    }

    private function markFailed(?Throwable $exception): void
    {
        $backfill = ImapBackfill::query()
            ->forTenant($this->tenantId)
            ->where('id', $this->backfillId)
            ->where('status', ImapBackfill::STATUS_DISCOVERING)
            ->first();
        if ($backfill === null) {
            return;
        }

        $terminal = [
            'message' => $exception?->getMessage() ?? 'IMAP discovery failed',
            'type' => $exception === null ? null : $exception::class,
            'at' => now()->toAtomString(),
        ];
        $error = (array) ($backfill->error_json ?? []);
        if ($error === []) {
            $error = [
                'message' => $terminal['message'],
                'type' => $terminal['type'],
            ];
        }
        $error['terminal_queue_error'] = $terminal;

        $backfill->forceFill([
            'status' => ImapBackfill::STATUS_FAILED,
            'heartbeat_at' => now(),
            'error_json' => $error,
        ])->save();

        Log::error('[imap-backfill-diag] discovery job reached terminal queue failure', [
            'backfill_id' => $this->backfillId,
            'tenant_id' => $this->tenantId,
            'handle_entered' => $this->diagnosticHandleEntered,
            'preserved_error' => $error,
            'exception' => $exception,
        ]);
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
