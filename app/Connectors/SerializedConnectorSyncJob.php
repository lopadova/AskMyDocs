<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Imap\MailboxLockKey;
use DateTimeInterface;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $installation = ConnectorInstallation::query()
            ->where('id', $this->installationId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        // Only IMAP shares a per-account connection limit; other connectors (and an
        // installation with no resolvable host/username) need no mailbox mutex.
        if ($installation === null || $installation->connector_name !== 'imap') {
            return [];
        }

        $key = MailboxLockKey::forInstallation($installation);
        if ($key === null) {
            return [];
        }

        return [
            (new WithoutOverlapping($key))
                ->releaseAfter((int) config('connectors.imap.mailbox_lock.requeue_after_seconds', 60))
                ->expireAfter((int) config('connectors.imap.mailbox_lock.ttl_seconds', 700)),
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
