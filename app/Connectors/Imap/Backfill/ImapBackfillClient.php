<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

/**
 * Bulk/date-window capabilities needed by the durable IMAP backfill.
 *
 * This deliberately lives host-side: the connector package's public client
 * surface remains sufficient for incremental syncs, while full-history imports
 * need bounded UID ranges and bulk message fetches. Implementations are created
 * by the same decorated factory chain as normal IMAP clients.
 */
interface ImapBackfillClient
{
    /** @return list<string> */
    public function mailboxes(): array;

    public function selectMailbox(string $mailbox): MailboxState;

    public function snapshotMailbox(string $mailbox): ImapBackfillMailboxSnapshot;

    /**
     * @return list<int>
     */
    public function uidsBetween(
        string $mailbox,
        Carbon $start,
        Carbon $end,
        int $afterUid = 0,
        ?int $throughUid = null,
        ?int $limit = null,
    ): array;

    public function fetchMessage(string $mailbox, int $uid): ImapMessage;

    /**
     * Read the server-assigned message date without parsing RFC822 headers.
     */
    public function internalDate(string $mailbox, int $uid): Carbon;

    /**
     * @param list<int> $uids
     * @return list<ImapMessage>
     */
    public function fetchMessages(string $mailbox, array $uids): array;

    public function close(): void;
}
