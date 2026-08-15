<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use App\Connectors\Imap\Backfill\ImapBackfillClient;
use App\Connectors\Imap\Backfill\ImapBackfillMailboxSnapshot;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

/** Holds the same per-account lock as every other live IMAP surface. */
final class SerializingImapBackfillClient implements ImapBackfillClient
{
    private ?Lock $lock = null;
    private bool $held = false;

    public function __construct(
        private readonly ImapBackfillClient $inner,
        private readonly LockProvider $lockProvider,
        private readonly string $lockKey,
        private readonly int $waitSeconds,
        private readonly int $ttlSeconds,
    ) {}

    public function mailboxes(): array
    {
        $this->acquire();
        return $this->inner->mailboxes();
    }

    public function selectMailbox(string $mailbox): MailboxState
    {
        $this->acquire();
        return $this->inner->selectMailbox($mailbox);
    }

    public function snapshotMailbox(string $mailbox): ImapBackfillMailboxSnapshot
    {
        $this->acquire();
        return $this->inner->snapshotMailbox($mailbox);
    }

    public function uidsBetween(
        string $mailbox,
        Carbon $start,
        Carbon $end,
        int $afterUid = 0,
        ?int $throughUid = null,
        ?int $limit = null,
    ): array {
        $this->acquire();
        return $this->inner->uidsBetween($mailbox, $start, $end, $afterUid, $throughUid, $limit);
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        $this->acquire();
        return $this->inner->fetchMessage($mailbox, $uid);
    }

    public function fetchMessages(string $mailbox, array $uids): array
    {
        $this->acquire();
        return $this->inner->fetchMessages($mailbox, $uids);
    }

    public function close(): void
    {
        try {
            $this->inner->close();
        } finally {
            $this->release();
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    private function acquire(): void
    {
        if ($this->held) {
            return;
        }

        $lock = $this->lockProvider->lock($this->lockKey, max(1, $this->ttlSeconds));
        try {
            $lock->block(max(0, $this->waitSeconds));
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

        try {
            $this->lock?->release();
        } catch (\Throwable) {
            // The TTL remains the crash backstop; never mask the real IMAP error.
        } finally {
            $this->lock = null;
            $this->held = false;
        }
    }
}
