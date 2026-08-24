<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

/** Bounded mailbox metadata captured without enumerating every UID. */
final readonly class ImapBackfillMailboxSnapshot
{
    public function __construct(
        public int $uidValidity,
        public int $maxUid,
        public int $messageCount,
    ) {}
}
