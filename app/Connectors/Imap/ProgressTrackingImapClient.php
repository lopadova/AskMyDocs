<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

/**
 * Observes the package IMAP stream without buffering message bodies.
 */
final class ProgressTrackingImapClient implements ImapClientInterface
{
    /** @var array<string,int> */
    private array $uidValidities = [];

    /**
     * The package selects a mailbox immediately before the UID search that
     * drives ingestion. Any later search for the same selected mailbox is an
     * auxiliary pass (currently deletion reconciliation) and must not create
     * fresh, unconfirmed ingestion work in the progress coordinator.
     *
     * @var array<string,bool>
     */
    private array $awaitingIngestionSearch = [];

    public function __construct(
        private readonly ImapClientInterface $inner,
        private readonly ImapSyncProgressContext $progress,
    ) {}

    public function listMailboxes(): array
    {
        return $this->inner->listMailboxes();
    }

    public function selectMailbox(string $name): MailboxState
    {
        $state = $this->inner->selectMailbox($name);
        $this->uidValidities[$name] = $state->uidValidity;
        $this->awaitingIngestionSearch[$name] = true;

        return $state;
    }

    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        $uids = $this->inner->searchUids($mailbox, $since, $sinceUid);

        if (($this->awaitingIngestionSearch[$mailbox] ?? false) === true) {
            $uidValidity = $this->uidValidities[$mailbox] ?? null;

            if ($uidValidity !== null) {
                $this->progress->observeSearch($mailbox, $uidValidity, $uids);
                $this->awaitingIngestionSearch[$mailbox] = false;
            }
        }

        return $uids;
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        $message = $this->inner->fetchMessage($mailbox, $uid);
        $this->progress->observeFetched($message);

        return $message;
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
