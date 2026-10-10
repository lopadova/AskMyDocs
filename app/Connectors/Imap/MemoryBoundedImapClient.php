<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use App\Connectors\Imap\Backfill\ImapBackfillClient;
use App\Connectors\Imap\Backfill\ImapBackfillMessageSizer;
use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

/** Use the same bounded large-message parser for incremental sync and backfill. */
final class MemoryBoundedImapClient implements ImapClientInterface
{
    public function __construct(
        private readonly ImapClientInterface $inner,
        private readonly ImapBackfillClient&ImapBackfillMessageSizer $downloads,
    ) {}

    public function listMailboxes(): array
    {
        return $this->inner->listMailboxes();
    }

    public function selectMailbox(string $name): MailboxState
    {
        return $this->inner->selectMailbox($name);
    }

    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        return $this->inner->searchUids($mailbox, $since, $sinceUid);
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        $sizes = $this->downloads->messageSizes($mailbox, [$uid]);
        if (($sizes[$uid] ?? 0) > MemoryBoundedImapMessage::RAW_SIZE_THRESHOLD) {
            return $this->downloads->fetchMessages($mailbox, [$uid])[0];
        }

        return $this->inner->fetchMessage($mailbox, $uid);
    }

    public function ping(): bool
    {
        return $this->inner->ping();
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
