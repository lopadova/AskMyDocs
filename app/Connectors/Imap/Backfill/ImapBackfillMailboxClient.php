<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
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
    private const BOUNDED_UID_INITIAL_SPAN = 1000;

    private const BOUNDED_UID_MAX_SPAN = 50000;

    /** @var array<string,int> */
    private array $uidValidity = [];

    public function __construct(
        private readonly Client $rawClient,
        private readonly ImapClientInterface $client,
    ) {}

    /** @return list<string> */
    public function mailboxes(): array
    {
        // Connect through the package client so its auth classification and
        // close() lifecycle remain intact, then retain LIST's folder attributes.
        if (! $this->client->ping()) {
            throw new RuntimeException('IMAP connection unavailable while listing backfill mailboxes.');
        }

        $mailboxes = [];
        // A flat LIST retains selectable descendants of a \Noselect container
        // (e.g. [Gmail]) without attempting STATUS/SELECT on the container itself.
        foreach ($this->rawClient->getFolders(false) as $folder) {
            if (! $folder->no_select) {
                $mailboxes[] = $folder->full_name; // Decoded UTF-8, not raw UTF7-IMAP.
            }
        }

        return $mailboxes;
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

    public function internalDate(string $mailbox, int $uid): Carbon
    {
        $connection = $this->rawClient->getConnection();
        if (! method_exists($connection, 'fetch')) {
            throw new RuntimeException('The configured IMAP protocol cannot fetch INTERNALDATE.');
        }

        $response = $connection->fetch(['INTERNALDATE'], [$uid], null, IMAP::ST_UID);
        // Validate command completion before considering any partial wire data.
        $dates = $response->validatedData();
        $value = is_array($dates) ? ($dates[$uid] ?? $dates[(string) $uid] ?? null) : null;

        // Webklex 6.2 splits a quoted string when its closing quote is followed
        // by ')'. Gmail sends exactly that shape: (UID n INTERNALDATE "...").
        // Recover the complete value from this same metadata-only response, not
        // another message's date or its RFC822 headers. Other shapes keep the
        // library's decoded value. No extra network command is needed.
        foreach ($response->getResponse() as $line) {
            if (is_string($line) && preg_match(
                '/\A\* [1-9][0-9]* FETCH \(UID '.$uid.' INTERNALDATE "([^"\r\n]+)"\)\r?\n?\z/i',
                $line,
                $match,
            )) {
                $value = $match[1];
                break;
            }
        }
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("IMAP did not return INTERNALDATE for UID {$uid}.");
        }

        try {
            $value = ltrim($value, ' '); // RFC 3501 also permits a space-padded day.
            if (! preg_match('/\A[0-9]{1,2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} [+-][0-9]{4}\z/', $value)) {
                throw new RuntimeException('Expected a complete IMAP date, time and numeric offset.');
            }
            $date = Carbon::createFromFormat('!j-M-Y H:i:s O', $value);
            $errors = Carbon::getLastErrors();
            if ($date === null || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new RuntimeException('Invalid IMAP calendar date or time.');
            }

            return $date;
        } catch (\Throwable $exception) {
            throw new RuntimeException("IMAP returned an invalid INTERNALDATE for UID {$uid}.", previous: $exception);
        }
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
        // The raw UID search criterion below must contain only protocol numbers,
        // never arbitrary strings supplied through PHP's untyped array elements.
        foreach ($uids as $uid) {
            if (! is_int($uid) || $uid < 1 || $uid > 4294967295) {
                throw new InvalidArgumentException('IMAP UIDs must be positive 32-bit integers.');
            }
        }
        $folder = $this->rawClient->getFolder($mailbox);
        if ($folder === null) {
            throw new RuntimeException("Mailbox not found: {$mailbox}");
        }

        try {
            $messages = [];
            // Webklex 6.2 quotes whereUidIn() as UID "1,2,3", but an IMAP
            // sequence-set is not a string (RFC 3501 section 9). CUSTOM leaves
            // this validated numeric criterion unquoted; other filters stay escaped.
            $query = $folder->query()->where('CUSTOM UID '.implode(',', $uids))->setSequence(IMAP::ST_UID);
            foreach ($query->get() as $rawMessage) {
                if ($rawMessage instanceof Message) {
                    $messages[] = $this->mapMessage($mailbox, $rawMessage);
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('[imap-backfill] bulk fetch degraded to per-message recovery', [
                'mailbox_hash' => substr(hash('sha256', $mailbox), 0, 16),
                'uid_count' => count($uids),
                'exception_type' => $exception::class,
            ]);
            $messages = [];
        }

        $returned = array_fill_keys(array_map(static fn (ImapMessage $message): int => $message->uid, $messages), true);
        foreach ($uids as $uid) {
            if (! isset($returned[$uid])) {
                $messages[] = $this->fetchMessageWithHeaderlessFallback($mailbox, $uid);
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
     *
     * Date windows and UID order are independent after messages are moved. A
     * fixed range as small as the result limit therefore degenerates into one
     * network round-trip per ~100 possible UIDs for sparse windows. Grow the UID
     * span according to the observed hit density while capping every SEARCH
     * reply. This keeps both memory and round-trips bounded.
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
        $span = min(
            self::BOUNDED_UID_MAX_SPAN,
            max($limit, self::BOUNDED_UID_INITIAL_SPAN),
        );

        while ($cursor <= $throughUid && count($uids) < $limit) {
            $rangeEnd = min($throughUid, $cursor + $span - 1);
            $rangeUids = $this->searchUids($mailbox, $start, $end, $cursor, $rangeEnd);
            foreach ($rangeUids as $uid) {
                $uids[] = $uid;
                if (count($uids) >= $limit) {
                    break;
                }
            }

            $remaining = $limit - count($uids);
            $scanned = $rangeEnd - $cursor + 1;
            if ($remaining > 0) {
                $span = $rangeUids === []
                    ? min(self::BOUNDED_UID_MAX_SPAN, $span * 4)
                    : min(
                        self::BOUNDED_UID_MAX_SPAN,
                        max(
                            self::BOUNDED_UID_INITIAL_SPAN,
                            (int) ceil($remaining * $scanned / count($rangeUids)),
                        ),
                    );
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
        // whereUid("1:1000") is quoted by Webklex 6.2 and rejected as BAD by
        // strict servers. These bounds are typed integers (or the literal '*'),
        // so emit only the UID criterion raw and retain normal date formatting.
        $query->where('CUSTOM UID '.max(1, $fromUid).':'.($throughUid ?? '*'));

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

    private function fetchMessageWithHeaderlessFallback(string $mailbox, int $uid): ImapMessage
    {
        try {
            return $this->client->fetchMessage($mailbox, $uid);
        } catch (\Throwable $exception) {
            $message = $this->headerlessMessage($mailbox, $uid);
            Log::warning('[imap-backfill] recovered message without RFC822 headers', [
                'mailbox_hash' => substr(hash('sha256', $mailbox), 0, 16),
                'uid' => $uid,
                'exception_type' => $exception::class,
            ]);

            return $message;
        }
    }

    private function headerlessMessage(string $mailbox, int $uid): ImapMessage
    {
        $uidValidity = (int) ($this->uidValidity[$mailbox] ?? 0);
        $date = $this->internalDate($mailbox, $uid);
        $connection = $this->rawClient->getConnection();
        $contents = $connection
            ->content([$uid], 'RFC822', IMAP::ST_UID)
            ->setCanBeEmpty(true)
            ->validatedData();
        $body = is_array($contents) ? ($contents[$uid] ?? $contents[(string) $uid] ?? '') : '';

        return new ImapMessage(
            uid: $uid,
            uidValidity: $uidValidity,
            mailbox: $mailbox,
            messageId: sprintf('imap-fallback-%d-%s-%d', $uidValidity, substr(hash('sha256', $mailbox), 0, 16), $uid),
            inReplyTo: null,
            references: [],
            fromName: '',
            fromEmail: '',
            to: [],
            cc: [],
            date: $date,
            subject: '',
            flags: [],
            labels: [],
            textBody: is_string($body) && $body !== '' ? $body : null,
            htmlBody: null,
            rawHeaders: ['x-askmydocs-recovery' => 'missing-rfc822-headers'],
            attachments: [],
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
