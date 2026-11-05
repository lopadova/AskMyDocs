<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

/** Optional metadata-only sizing; null means the client cannot provide sizes. */
interface ImapBackfillMessageSizer
{
    /**
     * @param list<int> $uids
     * @return array<int,int>|null UID => RFC822 bytes
     */
    public function messageSizes(string $mailbox, array $uids): ?array;
}
