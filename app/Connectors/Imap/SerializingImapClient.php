<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

/**
 * Decorates an {@see ImapClientInterface} so that at most ONE connection to a given
 * mailbox (account) is live at a time — across every surface AND every tenant.
 *
 * The lock is acquired LAZILY on the first connection-triggering call (the inner
 * client connects lazily too) and released on {@see close()}, so the lock lifetime
 * exactly brackets the live connection. On a wait-timeout the call throws
 * {@see MailboxBusyException} (→ 503 on the HTTP surfaces, R14). The lock carries a
 * TTL > the sync job timeout so a crashed process can never deadlock a mailbox; the
 * caller's `finally { close() }` is the normal release path.
 *
 * Cross-process correctness needs an atomic lock store (Redis in production). A
 * non-lock-capable store (file/array in dev) degrades to a process-local lock,
 * still correct within a single process.
 */
final class SerializingImapClient implements ImapClientInterface
{
    private ?Lock $lock = null;

    private bool $held = false;

    public function __construct(
        private readonly ImapClientInterface $inner,
        private readonly LockProvider $lockProvider,
        private readonly string $lockKey,
        private readonly int $waitSeconds,
        private readonly int $ttlSeconds,
    ) {}

    /** @return list<string> */
    public function listMailboxes(): array
    {
        $this->acquire();

        return $this->inner->listMailboxes();
    }

    public function selectMailbox(string $name): MailboxState
    {
        $this->acquire();

        return $this->inner->selectMailbox($name);
    }

    /** @return list<int> */
    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        $this->acquire();

        return $this->inner->searchUids($mailbox, $since, $sinceUid);
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        $this->acquire();

        return $this->inner->fetchMessage($mailbox, $uid);
    }

    public function ping(): bool
    {
        $this->acquire();

        return $this->inner->ping();
    }

    public function close(): void
    {
        try {
            $this->inner->close();
        } finally {
            $this->release();
        }
    }

    /**
     * Backstop release: not every caller brackets the client in a `finally`
     * (notably the vendor `ImapConnector::handleOAuthCallback` basic-auth check,
     * whose `ping()` THROWS on a wrong/expired password and never reaches its
     * close()). Releasing on destruction guarantees the cross-tenant mailbox lock
     * is freed when the client object dies — instead of leaking until the TTL and
     * blocking the account for every tenant for ~minutes. Idempotent + owner-safe:
     * a no-op after a normal close() or once the TTL already expired.
     */
    public function __destruct()
    {
        $this->release();
    }

    /**
     * Acquire the per-mailbox lock once, blocking up to wait_seconds for the
     * previous connection to free. Idempotent: later calls on the same client no-op.
     *
     * @throws MailboxBusyException  the mailbox stayed busy past the wait window.
     */
    private function acquire(): void
    {
        if ($this->held) {
            return;
        }

        // Clamp the env/config-sourced knobs defensively: a 0/negative TTL would
        // expire the lock immediately (no mutual exclusion at all), and a negative
        // wait window is meaningless to block(). A TTL needs ≥ 1s; the wait floor is
        // 0 (try-once, no blocking) so a misconfiguration degrades safely rather than
        // throwing or silently disabling serialization.
        $ttlSeconds = max(1, $this->ttlSeconds);
        $waitSeconds = max(0, $this->waitSeconds);

        $lock = $this->lockProvider->lock($this->lockKey, $ttlSeconds);

        try {
            // block() returns true on acquire, throws LockTimeoutException on timeout.
            $lock->block($waitSeconds);
        } catch (LockTimeoutException $e) {
            throw new MailboxBusyException(
                'Mailbox busy: another connection to this account is already in progress.',
                previous: $e,
            );
        }

        $this->lock = $lock;
        $this->held = true;
    }

    private function release(): void
    {
        if (! $this->held) {
            return;
        }

        // release() is owner-checked + best-effort: if the TTL already expired and
        // another process re-acquired, it is a no-op (never releases someone else's
        // lock). Swallow any store hiccup — a release failure must never mask the
        // real close() outcome, nor (from __destruct, during shutdown) escalate to a
        // fatal "exception without a stack frame".
        try {
            $this->lock?->release();
        } catch (\Throwable) {
            // ignore — the lock TTL is the backstop.
        } finally {
            $this->lock = null;
            $this->held = false;
        }
    }
}
