<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Padosoft\AskMyDocsConnectorImap\Imap\WebklexImapClient;
use RuntimeException;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;

/**
 * One short-lived IMAP session used by discovery or one backfill batch.
 * Date-range UID searches use the server-side IMAP SINCE/BEFORE predicates;
 * only the selected UIDs are fetched with bodies.
 */
final class ImapBackfillMailboxClient
{
    /** @var array<string,int> */
    private array $uidValidity = [];

    private function __construct(
        private readonly Client $rawClient,
        private readonly WebklexImapClient $client,
    ) {}

    public static function forInstallation(
        ConnectorInstallation $installation,
        ConnectorRegistry $registry,
    ): self {
        $connector = $registry->get('imap');
        if (! $connector instanceof BaseConnector) {
            throw new RuntimeException('The IMAP connector is not installed.');
        }

        $secret = (string) ($connector->refreshTokenIfExpired($installation->id) ?? '');
        if ($secret === '') {
            throw new RuntimeException('The IMAP credential is missing or expired.');
        }

        $config = (array) ($installation->config_json ?? []);
        $authMode = (string) ($config['auth_mode'] ?? 'basic');
        $connection = (array) ($config['connection'] ?? []);

        // Never send a freshly minted Microsoft app-only token to a configurable
        // host. This mirrors the connector package's own security boundary.
        if ($authMode === 'xoauth2_client_credentials') {
            $provider = (array) config('connectors.providers.imap.client_credentials.microsoft', []);
            $connection['host'] = (string) ($provider['imap_host'] ?? 'outlook.office365.com');
            $connection['port'] = (int) ($provider['imap_port'] ?? 993);
            $connection['encryption'] = (string) ($provider['imap_encryption'] ?? 'ssl');
        }

        $manager = new ClientManager;
        $raw = $manager->make([
            'host' => (string) ($connection['host'] ?? ''),
            'port' => (int) ($connection['port'] ?? 993),
            'encryption' => (string) ($connection['encryption'] ?? 'ssl'),
            'validate_cert' => (bool) ($connection['validate_cert'] ?? true),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => $secret,
            'rfc' => 'BODY',
            'authentication' => in_array($authMode, ['xoauth2', 'xoauth2_client_credentials'], true)
                ? 'oauth'
                : null,
        ]);

        return new self($raw, new WebklexImapClient($raw));
    }

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

    /** @return list<int> */
    public function allUids(string $mailbox): array
    {
        return $this->uids($mailbox, null, null, 0);
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
    ): array {
        if ($throughUid !== null && $afterUid >= $throughUid) {
            return [];
        }

        return $this->uids($mailbox, $start, $end, $afterUid, $throughUid);
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
        if ($afterUid > 0) {
            $query->whereUid(($afterUid + 1).':'.($throughUid ?? '*'));
        } elseif ($throughUid !== null) {
            $query->whereUid('1:'.$throughUid);
        }

        $uids = array_map('intval', $query->search()->all());
        sort($uids, SORT_NUMERIC);

        return array_values(array_filter($uids, static fn (int $uid): bool => $uid > $afterUid));
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
