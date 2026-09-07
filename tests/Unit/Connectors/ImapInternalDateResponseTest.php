<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillMailboxClient;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\ResponseException;

final class ImapInternalDateResponseTest extends TestCase
{
    #[DataProvider('validResponses')]
    public function test_internal_date_preserves_the_complete_server_date(string $attributes, string $expected): void
    {
        [$client, $protocol] = $this->client(["* 1 FETCH ({$attributes})\r\n", "TAG1 OK Success\r\n"]);

        $this->assertSame($expected, $client->internalDate('INBOX', 2290)->toIso8601String());
        // Exercise the installed Webklex FETCH/readLine/decodeLine implementation,
        // stubbing only socket I/O. No headers, bodies or flags may be fetched.
        $this->assertSame(['TAG1 UID FETCH 2290:2290 (INTERNALDATE)'], $protocol->commands);
    }

    public static function validResponses(): array
    {
        return [
            'Gmail date last' => ['UID 2290 INTERNALDATE "07-Sep-2026 21:23:57 +0000"', '2026-09-07T21:23:57+00:00'],
            'UID last' => ['INTERNALDATE "07-Sep-2026 21:23:57 +0000" UID 2290', '2026-09-07T21:23:57+00:00'],
            'space padded day and negative offset' => ['UID 2290 INTERNALDATE " 7-Sep-2026 23:59:01 -0730"', '2026-09-07T23:59:01-07:30'],
            'positive offset' => ['UID 2290 INTERNALDATE "31-Dec-2025 00:01:02 +0530"', '2025-12-31T00:01:02+05:30'],
            'leap day' => ['UID 2290 INTERNALDATE "29-Feb-2024 10:30:00 +0000"', '2024-02-29T10:30:00+00:00'],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_missing_or_invalid_dates_fail_without_inventing_a_date(array $lines): void
    {
        [$client] = $this->client([...$lines, "TAG1 OK Success\r\n"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INTERNALDATE for UID 2290');
        $client->internalDate('INBOX', 2290);
    }

    public static function invalidResponses(): array
    {
        return [
            'different UID' => [["* 1 FETCH (UID 2291 INTERNALDATE \"07-Sep-2026 21:23:57 +0000\")\r\n"]],
            'truncated date must not become midnight' => [["* 1 FETCH (INTERNALDATE \"07-Sep-2026\" UID 2290)\r\n"]],
            'invalid calendar date must not roll over' => [["* 1 FETCH (INTERNALDATE \"31-Feb-2026 10:30:00 +0000\" UID 2290)\r\n"]],
            'invalid time must not roll over' => [["* 1 FETCH (INTERNALDATE \"07-Sep-2026 25:30:00 +0000\" UID 2290)\r\n"]],
        ];
    }

    public function test_unsolicited_other_uid_date_cannot_override_the_requested_message(): void
    {
        [$client] = $this->client([
            "* 1 FETCH (UID 2291 INTERNALDATE \"01-Jan-2000 00:00:00 +0000\")\r\n",
            "* 2 FETCH (UID 2290 INTERNALDATE \"07-Sep-2026 21:23:57 +0000\")\r\n",
            "TAG1 OK Success\r\n",
        ]);

        $this->assertSame('2026-09-07T21:23:57+00:00', $client->internalDate('INBOX', 2290)->toIso8601String());
    }

    public function test_a_failed_fetch_is_not_recovered_from_its_partial_response(): void
    {
        [$client] = $this->client([
            "* 1 FETCH (UID 2290 INTERNALDATE \"07-Sep-2026 21:23:57 +0000\")\r\n",
            "TAG1 NO Fetch failed\r\n",
        ]);

        $this->expectException(ResponseException::class);
        $client->internalDate('INBOX', 2290);
    }

    public function test_an_expunged_uid_keeps_the_library_empty_response_error(): void
    {
        [$client] = $this->client(["TAG1 OK Success\r\n"]);

        $this->expectException(ResponseException::class);
        $client->internalDate('INBOX', 2290);
    }

    private function client(array $lines): array
    {
        $protocol = new InternalDateWireProtocol($lines);
        $raw = $this->createStub(InternalDateWireClient::class);
        $raw->method('getConnection')->willReturn($protocol);

        return [new ImapBackfillMailboxClient($raw, $this->createStub(ImapClientInterface::class)), $protocol];
    }
}

class InternalDateWireClient extends Client
{
    public function __destruct() {}
}

final class InternalDateWireProtocol extends ImapProtocol
{
    public array $commands = [];

    public function __construct(private array $lines)
    {
        parent::__construct(Config::make());
    }

    public function __destruct() {}

    public function write(Response $response, string $data): void
    {
        $this->commands[] = $data;
        $response->addCommand($data."\r\n");
    }

    public function nextLine(Response $response): string
    {
        $line = array_shift($this->lines) ?? throw new RuntimeException('Unexpected socket read');
        $response->addResponse($line);

        return $line;
    }
}
