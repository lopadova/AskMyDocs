<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillMailboxClient;
use Padosoft\AskMyDocsConnectorImap\Imap\WebklexImapClient;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP;

final class ImapBackfillMailboxClientTest extends TestCase
{
    public function test_internal_date_does_not_fetch_or_parse_rfc822_headers(): void
    {
        $uid = 834890;
        $connection = new RecordingInternalDateProtocol(
            Response::empty()->setResult([$uid => '15-Jan-2026 10:30:00 +0000']),
        );
        $rawClient = new InternalDateTestClient($connection);
        $client = new ImapBackfillMailboxClient($rawClient, new WebklexImapClient($rawClient));

        $date = $client->internalDate('INBOX', $uid);

        $this->assertSame('2026-01-15T10:30:00+00:00', $date->toIso8601String());
        $this->assertSame(['INTERNALDATE'], $connection->items);
        $this->assertSame([$uid], $connection->from);
        $this->assertSame(IMAP::ST_UID, $connection->sequence);
    }
}

final class RecordingInternalDateProtocol extends ImapProtocol
{
    /** @var array<int,string>|string */
    public array|string $items = [];
    /** @var array<int,int>|int */
    public array|int $from = [];
    public int|string $sequence = IMAP::ST_MSGN;

    public function __construct(private readonly Response $response) {}

    public function __destruct() {}

    public function fetch(array|string $items, array|int $from, mixed $to = null, int|string $uid = IMAP::ST_UID): Response
    {
        $this->items = $items;
        $this->from = $from;
        $this->sequence = $uid;

        return $this->response;
    }
}

final class InternalDateTestClient extends Client
{
    public function __construct(private readonly ProtocolInterface $testConnection) {}

    public function getConnection(): ProtocolInterface
    {
        return $this->testConnection;
    }

    public function disconnect(): Client
    {
        return $this;
    }
}
