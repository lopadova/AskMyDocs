<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Imap\MailboxLockKey;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * Host replacement for {@see ConnectorSyncJob} that adds per-mailbox re-queue: two
 * sync jobs for the SAME IMAP account never run at once — the second is released
 * back to the queue (no worker block, no spurious ERRORED), so the server never sees
 * "Too many simultaneous connections". Inherits all sync behaviour from the parent;
 * only the queue envelope changes.
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
     * Route the sync of $installation to the correct queue job. The per-mailbox
     * serialized envelope (tries=0 + retryUntil + WithoutOverlapping) only makes
     * sense for an IMAP account while {@see serializes()} holds; every other
     * connector — and IMAP with serialization disabled via
     * `connectors.imap.serialize_connections` — keeps the vendor
     * {@see ConnectorSyncJob} and its unchanged retry semantics.
     *
     * Single source of truth for the routing decision so the scheduled sweep and
     * the admin "Sync now" path can never drift apart.
     */
    public static function dispatchFor(ConnectorInstallation $installation): void
    {
        if (self::serializes($installation)) {
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
     *      crashes the worker.
     *
     * When any fails, {@see dispatchFor()} degrades to the vendor {@see ConnectorSyncJob}
     * (unchanged envelope) and {@see middleware()} adds no mutex — a clean no-op, never
     * a crash.
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
}
