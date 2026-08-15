<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

final readonly class ImapBackfillBatchResult
{
    public function __construct(
        public int $expectedMessages,
        public int $processedMessages,
        public int $dispatchedDocuments,
        public int $lastUid,
        public bool $hasMore,
    ) {}
}
