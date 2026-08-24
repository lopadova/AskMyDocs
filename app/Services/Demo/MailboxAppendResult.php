<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Result of one streaming mailbox delivery.
 */
final readonly class MailboxAppendResult
{
    public function __construct(
        public int $appended,
        public int $alreadyPresent = 0,
    ) {}

    public function processed(): int
    {
        return $this->appended + $this->alreadyPresent;
    }
}
