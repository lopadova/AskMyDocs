<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillMailboxClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Webklex\PHPIMAP\Client;
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
        return $this->testFolder;
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

    public function whereUidIn(array $uids): static
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
