<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillMailboxClient;
use App\Connectors\Imap\MemoryBoundedImapClient;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

final class ImapBackfillMailboxClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::swap(new NullLogger);
    }

    public function test_internal_date_does_not_fetch_or_parse_rfc822_headers(): void
    {
        $uid = 834890;
        $connection = new RecordingInternalDateProtocol(
            Response::empty()->setResult([$uid => '15-Jan-2026 10:30:00 +0000']),
        );
        $rawClient = new InternalDateTestClient($connection);
        $client = new ImapBackfillMailboxClient($rawClient, new HeaderlessImapClient);

        $date = $client->internalDate('INBOX', $uid);

        $this->assertSame('2026-01-15T10:30:00+00:00', $date->toIso8601String());
        $this->assertSame(['INTERNALDATE'], $connection->items);
        $this->assertSame([$uid], $connection->from);
        $this->assertSame(IMAP::ST_UID, $connection->sequence);
        $this->assertSame(['INBOX'], $rawClient->openedFolders);
    }

    public function test_bulk_failure_recovers_a_headerless_message_without_dropping_its_uid(): void
    {
        $uid = 834890;
        $connection = new RecordingInternalDateProtocol(
            Response::empty()->setResult([$uid => '15-Jan-2026 10:30:00 +0000']),
            Response::empty()->setResult([$uid => 'recoverable raw body']),
        );
        $rawClient = new InternalDateTestClient($connection, new FailingBulkFolder);
        $client = new ImapBackfillMailboxClient($rawClient, new HeaderlessImapClient);
        $client->selectMailbox('INBOX');

        $messages = $client->fetchMessages('INBOX', [$uid]);

        $this->assertCount(1, $messages);
        $this->assertSame($uid, $messages[0]->uid);
        $this->assertSame(77, $messages[0]->uidValidity);
        $this->assertSame('recoverable raw body', $messages[0]->textBody);
        $this->assertSame('missing-rfc822-headers', $messages[0]->rawHeaders['x-askmydocs-recovery']);
    }

    public function test_bounded_uid_search_expands_sparse_ranges_without_excessive_round_trips(): void
    {
        $expected = range(150000, 150100);
        $folder = new SparseUidFolder($expected);
        $rawClient = new InternalDateTestClient(
            new RecordingInternalDateProtocol(Response::empty()),
            $folder,
        );
        $client = new ImapBackfillMailboxClient($rawClient, new HeaderlessImapClient);

        $uids = $client->uidsBetween(
            'INBOX',
            Carbon::parse('1970-01-01'),
            Carbon::parse('2025-03-01'),
            throughUid: 200000,
            limit: 101,
        );

        $this->assertSame($expected, $uids);
        $this->assertCount(6, $folder->ranges);
        foreach ($folder->ranges as [$from, $to]) {
            $this->assertLessThanOrEqual(50000, $to - $from + 1);
        }
    }

    public function test_oversized_message_uses_the_bounded_parser_and_keeps_attachments(): void
    {
        $protocol = new OversizedMimeTestProtocol;
        $rawClient = new OversizedMimeTestClient($protocol);
        $client = new ImapBackfillMailboxClient($rawClient, new HeaderlessImapClient);
        $client->messageSizes('INBOX', [42]);

        $messages = $client->fetchMessages('INBOX', [42]);

        $this->assertCount(1, $messages);
        $this->assertSame(42, $messages[0]->uid);
        $this->assertSame(77, $messages[0]->uidValidity);
        $this->assertSame('body', $messages[0]->textBody);
        $this->assertCount(1, $messages[0]->attachments);
        $this->assertSame('%PDF-1.7 attachment', $messages[0]->attachments[0]->contents);
        $this->assertSame(['sizes', 'flags', 'headers', 'content'], $protocol->calls);
    }

    public function test_incremental_sync_uses_the_same_oversized_parser_instead_of_the_package_fetch(): void
    {
        $protocol = new OversizedMimeTestProtocol;
        $rawClient = new OversizedMimeTestClient($protocol);
        // This package client rejects fetchMessage, so delegating there would
        // fail the test and reproduce the old unbounded single-message path.
        $package = new HeaderlessImapClient;
        $client = new MemoryBoundedImapClient($package, new ImapBackfillMailboxClient($rawClient, $package));

        $message = $client->fetchMessage('INBOX', 42);

        $this->assertSame('body', $message->textBody);
        $this->assertSame('%PDF-1.7 attachment', $message->attachments[0]->contents);
        $this->assertSame(['sizes', 'flags', 'headers', 'content'], $protocol->calls);
    }
}

final class OversizedMimeTestProtocol extends ImapProtocol
{
    public array $calls = [];

    public function __construct() {}

    public function __destruct() {}

    public function fetch(array|string $items, array|int $from, mixed $to = null, int|string $uid = IMAP::ST_UID): Response
    {
        $this->calls[] = 'sizes';

        return Response::empty()->setResult([42 => 32 * 1024 * 1024]);
    }

    public function flags(int|array $uids, int|string $uid = IMAP::ST_UID): Response
    {
        $this->calls[] = 'flags';

        return Response::empty()->setResult([42 => ['\\Seen']]);
    }

    public function headers(int|array $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): Response
    {
        $this->calls[] = 'headers';

        return Response::empty()->setResult([42 => "Content-Type: multipart/mixed; boundary=bound\r\n"]);
    }

    public function content(int|array $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): Response
    {
        $this->calls[] = 'content';

        return Response::empty()->setResult([42 => "--bound\r\nContent-Type: text/plain\r\n\r\nbody\r\n"
            ."--bound\r\nContent-Type: application/pdf; name=report.pdf\r\nContent-Disposition: attachment\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .base64_encode('%PDF-1.7 attachment')."\r\n--bound--\r\n"]);
    }
}

final class OversizedMimeTestClient extends Client
{
    public function __construct(private readonly ProtocolInterface $testConnection)
    {
        parent::__construct((new ClientManager)->getConfig());
    }

    public function getConnection(): ProtocolInterface
    {
        return $this->testConnection;
    }

    public function getFolderPath(): string
    {
        return 'INBOX';
    }

    public function getFolder(string $folder_name, ?string $delimiter = null, bool $utf7 = false): ?Folder
    {
        $folder = new FailingBulkFolder;
        $folder->path = $folder_name;

        return $folder;
    }

    public function openFolder(string $folder_path, bool $force_select = false): array
    {
        return [];
    }

    public function disconnect(): Client
    {
        return $this;
    }
}

final class RecordingInternalDateProtocol extends ImapProtocol
{
    /** @var array<int,string>|string */
    public array|string $items = [];
    /** @var array<int,int>|int */
    public array|int $from = [];
    public int|string $sequence = IMAP::ST_MSGN;

    public function __construct(
        private readonly Response $response,
        private readonly ?Response $contentResponse = null,
    ) {}

    public function __destruct() {}

    public function fetch(array|string $items, array|int $from, mixed $to = null, int|string $uid = IMAP::ST_UID): Response
    {
        $this->items = $items;
        $this->from = $from;
        $this->sequence = $uid;

        return $this->response;
    }

    public function content(int|array $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): Response
    {
        return $this->contentResponse ?? Response::empty()->setResult([]);
    }
}

final class InternalDateTestClient extends Client
{
    public array $openedFolders = [];
    public function __construct(
        private readonly ProtocolInterface $testConnection,
        private readonly ?Folder $testFolder = null,
    ) {}

    public function getConnection(): ProtocolInterface
    {
        return $this->testConnection;
    }

    public function getFolder(string $folder_name, ?string $delimiter = null, bool $utf7 = false): ?Folder
    {
        $folder = $this->testFolder ?? new FailingBulkFolder;
        $folder->path = $folder_name;

        return $folder;
    }

    public function openFolder(string $folder_path, bool $force_select = false): array
    {
        $this->openedFolders[] = $folder_path;

        return [];
    }

    public function disconnect(): Client
    {
        return $this;
    }
}

final class FailingBulkFolder extends Folder
{
    public function __construct() {}

    public function query(array $extensions = []): WhereQuery
    {
        return new FailingBulkQuery;
    }
}

final class FailingBulkQuery extends WhereQuery
{
    public function __construct() {}

    public function where(mixed $criteria, mixed $value = null): static
    {
        return $this;
    }

    public function setSequence(int $sequence): static
    {
        return $this;
    }

    public function get(): MessageCollection
    {
        throw new RuntimeException('bulk header response was empty');
    }
}

final class SparseUidFolder extends Folder
{
    /** @var list<array{int,int}> */
    public array $ranges = [];

    /** @param list<int> $matches */
    public function __construct(public readonly array $matches) {}

    public function query(array $extensions = []): WhereQuery
    {
        return new SparseUidQuery($this);
    }
}

final class SparseUidQuery extends WhereQuery
{
    private int $fromUid = 1;

    private int $throughUid = 1;

    public function __construct(private readonly SparseUidFolder $folder) {}

    public function __call(string $name, ?array $arguments): mixed
    {
        return $this;
    }

    public function setSequence(int $sequence): static
    {
        return $this;
    }

    public function where(mixed $criteria, mixed $value = null): static
    {
        if (! is_string($criteria) || ! preg_match('/^CUSTOM UID ([1-9][0-9]*):([1-9][0-9]*)$/D', $criteria, $matches)) {
            throw new RuntimeException('Expected a raw numeric UID range.');
        }
        [$from, $through] = [(int) $matches[1], (int) $matches[2]];
        $this->fromUid = $from;
        $this->throughUid = $through;

        return $this;
    }

    public function search(): Collection
    {
        $this->folder->ranges[] = [$this->fromUid, $this->throughUid];

        return collect(array_values(array_filter(
            $this->folder->matches,
            fn (int $uid): bool => $uid >= $this->fromUid && $uid <= $this->throughUid,
        )));
    }
}

final class HeaderlessImapClient implements ImapClientInterface
{
    public function listMailboxes(): array
    {
        return ['INBOX'];
    }

    public function selectMailbox(string $name): MailboxState
    {
        return new MailboxState(uidValidity: 77, lastUid: 834890);
    }

    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        return [];
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        throw new RuntimeException('no headers found');
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): void {}
}
