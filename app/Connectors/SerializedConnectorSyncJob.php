<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Imap\ImapSyncProgressContext;
use App\Connectors\Imap\MailboxBusyException;
use App\Connectors\Imap\MailboxLockKey;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * Host replacement for {@see ConnectorSyncJob} that adds two IMAP guarantees:
 * per-mailbox re-queue and bounded, resumable UID progress. Two sync jobs for the
 * SAME IMAP account never run at once, while a mailbox larger than the connector's
 * per-run cap resumes from its last confirmed message on the next run.
 *
 * Dispatched by {@see \App\Connectors\Scheduling\SerializedSyncScheduler} (the
 * scheduled sweep) and by the admin "Sync now" controller. Non-IMAP connectors get
 * no overlap middleware — they don't share a per-account connection limit.
 *
 * Layer 1 (the IMAP factory decorator) remains the hard "one connection per mailbox"
 * guarantee across ALL surfaces; this Layer 2 just keeps sync JOBS off each other so
 * they re-queue cleanly instead of blocking on that lock.
 */
final class SerializedConnectorSyncJob extends ConnectorSyncJob
{
    /**
     * Preserve a fast fail on REAL errors while allowing a busy mailbox to keep re-queuing.
     *
     * We disable the max-attempts cap (tries=0) and bound retries by wall-clock via retryUntil().
     * WithoutOverlapping re-queues don’t throw, so they don’t count toward maxExceptions.
     */
    public int $maxExceptions = 3;

    /**
     * Busy-mailbox re-queues can increment attempts; keep them from hitting a max-attempts cap.
     */
    public int $tries = 0;

    /**
     * Route every IMAP sync through this host envelope. The overlap middleware
     * remains conditional via {@see serializes()}, but UID progress tracking is
     * required even when the mutex is disabled or unavailable. Other connectors
     * keep the vendor {@see ConnectorSyncJob}.
     *
     * Single source of truth for the routing decision so the scheduled sweep and
     * the admin "Sync now" path can never drift apart.
     */
    public static function dispatchFor(ConnectorInstallation $installation): void
    {
        if ($installation->connector_name === 'imap') {
            self::dispatch($installation->id, $installation->tenant_id);

            return;
        }

        ConnectorSyncJob::dispatch($installation->id, $installation->tenant_id);
    }

    /**
     * Whether $installation should use the per-mailbox serialized envelope.
     *
     * The conditions MIRROR the Layer-1 factory decorator's gating in
     * {@see \App\Providers\AppServiceProvider::registerImapConnectionSerializer()} —
     * the serialized envelope leans on `WithoutOverlapping`, i.e. `Cache::lock()`,
     * so it must NOT be dispatched in any state where Layer 1 itself stands down:
     *
     *   1. an IMAP account (others share no per-account connection limit);
     *   2. `connectors.imap.serialize_connections` on (master switch, defaults on);
     *   3. the IMAP is NOT faked (`fake_imap_ping` — no real server to protect, and
     *      that seam's cache store may not host locks);
     *   4. the active cache store is lock-capable (a `LockProvider`) — otherwise
     *      `WithoutOverlapping` throws "this cache store does not support locks" and
     *      crashes the worker;
     *   5. the install resolves to a mailbox lock key (host+username present) —
     *      an unkeyable IMAP row gets no `WithoutOverlapping` either way, so the
     *      serialized envelope (tries=0 + retryUntil) would buy nothing.
     *
     * When any fails, {@see middleware()} adds no mutex — a clean no-op, never
     * a crash. {@see dispatchFor()} still keeps the host job because UID
     * checkpointing is independent from connection serialization.
     */
    public static function serializes(ConnectorInstallation $installation): bool
    {
        if ($installation->connector_name !== 'imap') {
            return false;
        }

        if (config('connectors.imap.serialize_connections', true) !== true) {
            return false;
        }

        if (config('connectors.fake_imap_ping', false) === true) {
            return false;
        }

        if (MailboxLockKey::forInstallation($installation) === null) {
            return false;
        }

        return self::cacheStoreSupportsLocks();
    }

    /**
     * True when the active cache store can host atomic locks (a `LockProvider` —
     * Redis/memcached/database/array). `WithoutOverlapping` needs this; a non-lock
     * store (e.g. file/null) would throw on `Cache::lock()`.
     */
    private static function cacheStoreSupportsLocks(): bool
    {
        try {
            return Cache::store()->getStore() instanceof LockProvider;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $installation = ConnectorInstallation::query()
            ->where('id', $this->installationId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        // No mutex unless serialization should actually run for this install (same
        // gating as dispatchFor): non-IMAP, flag off, fake-ping, or a non-lock-capable
        // store all skip it. This keeps a job that was enqueued under different
        // conditions — or dispatched directly — from crashing on Cache::lock().
        if ($installation === null || ! self::serializes($installation)) {
            return [];
        }

        $key = MailboxLockKey::forInstallation($installation);
        if ($key === null) {
            return [];
        }

        $releaseAfter = max(0, (int) config('connectors.imap.mailbox_lock.requeue_after_seconds', 60));
        $ttlSeconds = max(1, (int) config('connectors.imap.mailbox_lock.ttl_seconds', 700));

        return [
            (new WithoutOverlapping($key))
                ->releaseAfter($releaseAfter)
                ->expireAfter($ttlSeconds),
        ];
    }

    /**
     * Bound the retries by WALL-CLOCK, not attempt count, so WithoutOverlapping
     * re-queues (which DO increment attempts) can never exhaust the parent's
     * `$tries` and fail a legitimate sync that was merely waiting for a busy
     * mailbox. Real failures retry within the same window (inherited `$backoff`).
     */
    public function retryUntil(): DateTimeInterface
    {
        $minutes = (int) config('connectors.imap.mailbox_lock.requeue_window_minutes', 30);

        return now()->addMinutes(max(1, $minutes));
    }
    /**
     * Wrap the vendor sync with the generated-dataset progress coordinator and
     * recover a transient busy mailbox without leaving the installation errored.
     * The coordinator records only the contiguous prefix of UIDs whose parent
     * document and attachments were confirmed by the host ingestion bridge.
     */
    public function handle(
        ConnectorRegistry $registry,
        TenantContext $tenantContext,
        ?ImapSyncProgressContext $progress = null,
    ): void
    {
        $progress ??= app(ImapSyncProgressContext::class);
        $priorTenant = $tenantContext->current();
        $progressStarted = false;
        $priorLastSyncAt = null;

        try {
            $tenantContext->set($this->tenantId);

            $installation = ConnectorInstallation::query()
                ->where('id', $this->installationId)
                ->where('tenant_id', $this->tenantId)
                ->first();
            $tracksImap = $installation?->connector_name === 'imap';
            $priorLastSyncAt = $installation?->last_sync_at?->copy();

            if ($tracksImap) {
                $progress->begin($installation);
                $progressStarted = true;
            }

            try {
                parent::handle($registry, $tenantContext);
            } catch (MailboxBusyException) {
                $this->recoverFromMailboxBusy();

                $delay = max(1, (int) config('connectors.imap.mailbox_lock.requeue_after_seconds', 60));
                $this->release($delay);
            }
        } finally {
            try {
                if ($progressStarted) {
                    $hasUnconfirmedWork = $progress->hasUnconfirmedWork();

                    try {
                        $progress->finish();
                    } finally {
                        // A failed progress flush must not prevent the date
                        // watermark from being rolled back. Otherwise a later
                        // retry can exclude the historical UID/attachment that
                        // the progress coordinator deliberately left pending.
                        $this->restoreBackfillTimestampWhenIncomplete(
                            $priorLastSyncAt,
                            $hasUnconfirmedWork,
                        );
                    }
                }
            } finally {
                // parent::handle() restores to the tenant it observed on entry.
                // We set that entry value above so the progress coordinator and
                // vault share the same tenant, then restore the worker's real
                // prior tenant here.
                $tenantContext->set($priorTenant);
            }
        }
    }

    /**
     * A truncated backfill or a UID whose body/attachments were not all
     * confirmed is incomplete, not a successful date watermark. The vendor job
     * records completedAt even for partial SyncResults; that timestamp becomes
     * the next IMAP SINCE filter and can exclude historical mail still behind
     * the safe UID checkpoint. Restore the previous value so the next run can
     * resume from ImapSyncProgressContext's contiguous prefix.
     */
    private function restoreBackfillTimestampWhenIncomplete(
        ?Carbon $priorLastSyncAt,
        bool $hasUnconfirmedWork,
    ): void {
        $installation = ConnectorInstallation::query()
            ->where('id', $this->installationId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if ($installation === null) {
            return;
        }

        $errors = (array) ($installation->error_json['partial_errors'] ?? []);
        $incomplete = $hasUnconfirmedWork;

        foreach ($errors as $error) {
            if (
                is_string($error)
                && str_starts_with($error, 'sync truncated at max_messages_per_sync=')
            ) {
                $incomplete = true;
                break;
            }
        }

        if (! $incomplete) {
            return;
        }

        $saved = $installation->forceFill([
            'last_sync_at' => $priorLastSyncAt,
        ])->save();

        if (! $saved) {
            throw new \RuntimeException(
                "Unable to restore IMAP backfill watermark for installation {$this->installationId}.",
            );
        }
    }

    /**
     * Undo the ERRORED state written by the vendor job for a transient busy
     * mailbox, so the released job and the scheduler can retry an ACTIVE row.
     * The guards prevent this path from clearing an unrelated failure.
     */
    private function recoverFromMailboxBusy(): void
    {
        $installation = ConnectorInstallation::query()
            ->where('id', $this->installationId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if ($installation === null) {
            return;
        }

        if ($installation->status !== ConnectorInstallation::STATUS_ERRORED) {
            return;
        }

        if (($installation->error_json['class'] ?? null) !== MailboxBusyException::class) {
            return;
        }

        $saved = $installation->forceFill([
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'error_json' => null,
        ])->save();

        if (! $saved) {
            throw new \RuntimeException(
                "Unable to restore busy IMAP installation {$this->installationId} to active.",
            );
        }

        Log::info('[connector-imap] mailbox busy — re-queuing sync instead of failing', [
            'installation_id' => $this->installationId,
            'tenant_id' => $this->tenantId,
        ]);
    }
}
