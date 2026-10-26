<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use App\Connectors\Imap\Backfill\ImapBackfillMailboxClient;
use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

/** Share server-side UID filtering and bounded MIME parsing with backfill. */
final class MemoryBoundedImapClient implements ImapClientInterface
{
    public function __construct(
        private readonly ImapClientInterface $inner,
        private readonly ImapBackfillMailboxClient $downloads,
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
        return $this->downloads->incrementalUids($mailbox, $since, $sinceUid);
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
