<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Padosoft\AskMyDocsConnectorImap\Imap\WebklexImapClient;
use RuntimeException;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * One short-lived IMAP session used by discovery or one backfill batch.
 * Date-range UID searches use the server-side IMAP SINCE/BEFORE predicates;
 * only the selected UIDs are fetched with bodies.
 */
final class ImapBackfillMailboxClient implements ImapBackfillClient
{
    /** @var array<string,int> */
    private array $uidValidity = [];

    public function __construct(
        private readonly Client $rawClient,
        private readonly WebklexImapClient $client,
    ) {}

    /** @return list<string> */
    public function mailboxes(): array
    {
        return $this->client->listMailboxes();
    }

    public function selectMailbox(string $mailbox): MailboxState
    {
        $state = $this->client->selectMailbox($mailbox);
        $this->uidValidity[$mailbox] = $state->uidValidity;

        return $state;
    }

    public function snapshotMailbox(string $mailbox): ImapBackfillMailboxSnapshot
    {
        $state = $this->selectMailbox($mailbox);
        $folder = $this->rawClient->getFolder($mailbox);
        if ($folder === null) {
            throw new RuntimeException("Mailbox not found: {$mailbox}");
        }
        $status = $folder->status();

        return new ImapBackfillMailboxSnapshot(
            uidValidity: $state->uidValidity,
            maxUid: $state->lastUid,
            messageCount: max(0, (int) ($status['messages'] ?? 0)),
        );
    }

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
    ): array {
        if ($throughUid !== null && $afterUid >= $throughUid) {
            return [];
        }

        return $this->uids($mailbox, $start, $end, $afterUid, $throughUid, $limit);
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        return $this->client->fetchMessage($mailbox, $uid);
    }

    /**
     * Fetch headers, flags and bodies for many UIDs in one IMAP exchange. A
     * 20-message sub-batch replaces roughly 80 per-message network commands.
     *
     * @param list<int> $uids
     * @return list<ImapMessage>
     */
    public function fetchMessages(string $mailbox, array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $folder = $this->rawClient->getFolder($mailbox);
        if ($folder === null) {
            throw new RuntimeException("Mailbox not found: {$mailbox}");
        }

        $messages = [];
        foreach ($folder->query()->whereUidIn($uids)->setSequence(IMAP::ST_UID)->get() as $rawMessage) {
            if ($rawMessage instanceof Message) {
                $messages[] = $this->mapMessage($mailbox, $rawMessage);
            }
        }
        usort($messages, static fn (ImapMessage $a, ImapMessage $b): int => $a->uid <=> $b->uid);

        return $messages;
    }

    public function close(): void
    {
        $this->client->close();
    }

    /**
     * UID-only SEARCH: no headers and no bodies are downloaded here.
     *
     * @return list<int>
     */
    private function uids(
        string $mailbox,
        ?Carbon $start,
        ?Carbon $end,
        int $afterUid,
        ?int $throughUid = null,
        ?int $limit = null,
    ): array {
        if ($limit !== null && $throughUid !== null) {
            return $this->boundedUids($mailbox, $start, $end, $afterUid, $throughUid, $limit);
        }

        return $this->searchUids($mailbox, $start, $end, $afterUid + 1, $throughUid);
    }

    /**
     * Search bounded UID ranges on the server until limit results are collected.
     * A range contains at most limit possible UIDs, so neither one SEARCH reply
     * nor the accumulated PHP list can grow with the remaining mailbox history.
     *
     * @return list<int>
     */
    private function boundedUids(
        string $mailbox,
        ?Carbon $start,
        ?Carbon $end,
        int $afterUid,
        int $throughUid,
        int $limit,
    ): array {
        $limit = max(1, $limit);
        $cursor = max(1, $afterUid + 1);
        $uids = [];

        while ($cursor <= $throughUid && count($uids) < $limit) {
            $rangeEnd = min($throughUid, $cursor + $limit - 1);
            foreach ($this->searchUids($mailbox, $start, $end, $cursor, $rangeEnd) as $uid) {
                $uids[] = $uid;
                if (count($uids) >= $limit) {
                    break;
                }
            }
            $cursor = $rangeEnd + 1;
        }

        return $uids;
    }

    /** @return list<int> */
    private function searchUids(
        string $mailbox,
        ?Carbon $start,
        ?Carbon $end,
        int $fromUid,
        ?int $throughUid,
    ): array {
        $folder = $this->rawClient->getFolder($mailbox);
        if ($folder === null) {
            throw new RuntimeException("Mailbox not found: {$mailbox}");
        }

        $query = $folder->query()->setSequence(IMAP::ST_UID);
        if ($start !== null) {
            $query->since($start);
        } else {
            $query->all();
        }
        if ($end !== null) {
            $query->before($end);
        }
        $query->whereUid(max(1, $fromUid).':'.($throughUid ?? '*'));

        $uids = array_map('intval', $query->search()->all());
        sort($uids, SORT_NUMERIC);

        return array_values(array_filter(
            $uids,
            static fn (int $uid): bool => $uid >= $fromUid && ($throughUid === null || $uid <= $throughUid),
        ));
    }

    private function mapMessage(string $mailbox, Message $message): ImapMessage
    {
        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = new ImapAttachment(
                filename: (string) $attachment->getName(),
                mimeType: (string) ($attachment->getMimeType() ?? $attachment->getContentType()),
                sizeBytes: (int) $attachment->getSize(),
                isInline: strtolower((string) $attachment->getDisposition()) === 'inline',
                contents: (string) $attachment->getContent(),
            );
        }

        $fromRaw = $message->getFrom()->get(0);
        $from = $fromRaw instanceof Address ? $fromRaw : null;
        $date = null;
        if ($message->getDate()->count() > 0) {
            try {
                $date = $message->getDate()->toDate();
            } catch (\Throwable) {
                $date = null;
            }
        }

        return new ImapMessage(
            uid: (int) $message->getUid(),
            uidValidity: (int) ($this->uidValidity[$mailbox] ?? 0),
            mailbox: $mailbox,
            messageId: (string) $message->getMessageId(),
            inReplyTo: $this->attributeStringOrNull($message->getInReplyTo()),
            references: $this->splitRefs((string) $message->getReferences()),
            fromName: $from?->personal ?? '',
            fromEmail: $from?->mail ?? '',
            to: $this->addresses($message->getTo()),
            cc: $this->addresses($message->getCc()),
            date: $date,
            subject: (string) $message->getSubject(),
            flags: array_values((array) $message->getFlags()->all()),
            labels: [],
            textBody: $message->hasTextBody() ? $message->getTextBody() : null,
            htmlBody: $message->hasHTMLBody() ? $message->getHTMLBody() : null,
            rawHeaders: $this->headers($message),
            attachments: $attachments,
        );
    }

    /** @return list<array{name:string,email:string}> */
    private function addresses(mixed $attribute): array
    {
        if (! $attribute instanceof Attribute) {
            return [];
        }
        $addresses = [];
        foreach ($attribute->all() as $address) {
            if ($address instanceof Address) {
                $addresses[] = ['name' => $address->personal, 'email' => $address->mail];
            }
        }

        return $addresses;
    }

    /** @return array<string,string> */
    private function headers(Message $message): array
    {
        $header = $message->getHeader();
        if ($header === null) {
            return [];
        }
        $headers = [];
        foreach (['precedence', 'auto-submitted', 'list-unsubscribe'] as $name) {
            $value = $header->get($name)->toString();
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /** @return list<string> */
    private function splitRefs(string $references): array
    {
        return $references === ''
            ? []
            : array_values(array_filter(preg_split('/[\s,]+/', $references) ?: []));
    }

    private function attributeStringOrNull(mixed $attribute): ?string
    {
        if (! $attribute instanceof Attribute) {
            return null;
        }
        $value = $attribute->toString();

        return $value !== '' ? $value : null;
    }
}
