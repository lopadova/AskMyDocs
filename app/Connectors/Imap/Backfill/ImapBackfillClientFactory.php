<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

/**
 * Optional bulk extension implemented by every layer of the shared IMAP factory.
 */
interface ImapBackfillClientFactory
{
    /** @param array<string,mixed> $connection */
    public function makeBackfill(array $connection, string $secret, string $authMode): ImapBackfillClient;
}
