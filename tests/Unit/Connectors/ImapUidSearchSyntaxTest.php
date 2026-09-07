<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillMailboxClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Query\WhereQuery;

final class ImapUidSearchSyntaxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::swap(new NullLogger);
    }

    public function test_discovery_sends_an_unquoted_uid_range_with_unchanged_date_filters(): void
    {
        [$client, $protocol] = $this->client([999, 3, 2]);

        $uids = $client->uidsBetween(
            'INBOX', Carbon::parse('1970-01-01'), Carbon::parse('2026-09-08'),
            throughUid: 200000, limit: 3,
        );

        $this->assertSame([2, 3, 999], $uids);
        $this->assertSame([
            'UID SEARCH SINCE "01-Jan-1970" BEFORE "08-Sep-2026" UID 1:1000',
        ], $protocol->commands);
    }

    public function test_unbounded_search_keeps_the_uid_wildcard_and_checkpoint_filter(): void
    {
        [$client, $protocol] = $this->client([72, 8, 71]);

        $uids = $client->uidsBetween(
            'INBOX', Carbon::parse('2026-01-01'), Carbon::parse('2026-02-01'),
            afterUid: 70,
        );

        $this->assertSame([71, 72], $uids);
        $this->assertSame([
            'UID SEARCH SINCE "01-Jan-2026" BEFORE "01-Feb-2026" UID 71:*',
        ], $protocol->commands);
    }

    public function test_bounded_search_keeps_both_uid_checkpoint_limits(): void
    {
        [$client, $protocol] = $this->client([9999, 200, 70, 71]);

        $uids = $client->uidsBetween(
            'INBOX', Carbon::parse('2026-01-01'), Carbon::parse('2026-02-01'),
            afterUid: 70, throughUid: 200,
        );

        $this->assertSame([71, 200], $uids);
        $this->assertSame([
            'UID SEARCH SINCE "01-Jan-2026" BEFORE "01-Feb-2026" UID 71:200',
        ], $protocol->commands);
    }

    #[DataProvider('validUidLists')]
    public function test_bulk_search_sends_an_unquoted_uid_set_and_preserves_missing_message_recovery(array $uids): void
    {
        // An empty SEARCH response also exercises the existing per-UID recovery
        // without needing to fake Webklex's query builder or download any bodies.
        $inner = $this->createMock(ImapClientInterface::class);
        [$client, $protocol] = $this->client([], $inner);
        $inner->expects($this->exactly(count($uids)))->method('fetchMessage')
            ->willReturnCallback(fn (string $mailbox, int $uid): ImapMessage => $this->message($uid));

        $messages = $client->fetchMessages('INBOX', $uids);

        $expected = $uids;
        sort($expected, SORT_NUMERIC);
        $this->assertSame($expected, array_map(static fn (ImapMessage $message): int => $message->uid, $messages));
        $this->assertSame(['UID SEARCH UID '.implode(',', $uids)], $protocol->commands);
    }

    public static function validUidLists(): array
    {
        return [
            'multiple UIDs' => [[70000, 42, 1009]],
            'single UID' => [[42]],
            'maximum unsigned 32-bit UID' => [[4294967295]],
        ];
    }

    #[DataProvider('invalidUidLists')]
    public function test_invalid_bulk_uids_are_rejected_before_opening_a_folder_or_running_recovery(array $uids): void
    {
        $raw = $this->createMock(UidSearchTestClient::class);
        $raw->method('disconnect')->willReturnSelf();
        $raw->expects($this->never())->method('getFolder');
        $inner = $this->createMock(ImapClientInterface::class);
        $inner->expects($this->never())->method('fetchMessage');
        $client = new ImapBackfillMailboxClient($raw, $inner);

        $this->expectException(InvalidArgumentException::class);
        $client->fetchMessages('INBOX', $uids);
    }

    public static function invalidUidLists(): array
    {
        return [
            'zero' => [[0]],
            'negative' => [[-1]],
            'float' => [[1.5]],
            'larger than unsigned 32-bit' => [[4294967296]],
            'raw range' => [['1:1000']],
            'search criterion injection' => [[42, '1 ALL']],
            'command injection' => [["1\r\nA1 LOGOUT"]],
        ];
    }

    public function test_an_empty_uid_list_does_not_open_a_folder(): void
    {
        $raw = $this->createMock(UidSearchTestClient::class);
        $raw->method('disconnect')->willReturnSelf();
        $raw->expects($this->never())->method('getFolder');
        $client = new ImapBackfillMailboxClient($raw, $this->createStub(ImapClientInterface::class));

        $this->assertSame([], $client->fetchMessages('INBOX', []));
    }

    /** @return array{ImapBackfillMailboxClient, UidSearchRecordingProtocol} */
    private function client(array $searchResults, ?ImapClientInterface $inner = null): array
    {
        $protocol = new UidSearchRecordingProtocol($searchResults);
        $raw = $this->createStub(UidSearchTestClient::class);
        $raw->method('disconnect')->willReturnSelf();
        $raw->method('getConfig')->willReturn(Config::make(['date_format' => 'd-M-Y']));
        $raw->method('getConnection')->willReturn($protocol);
        $folder = $this->createStub(Folder::class);
        // Use the installed library's actual criteria parsing, quoting and SEARCH
        // implementation. Stub only folder I/O and the final server exchange.
        $folder->method('query')->willReturnCallback(static fn (): WhereQuery => new WhereQuery($raw));
        $raw->method('getFolder')->willReturn($folder);
        $inner ??= $this->createStub(ImapClientInterface::class);

        return [new ImapBackfillMailboxClient($raw, $inner), $protocol];
    }

    private function message(int $uid): ImapMessage
    {
        return new ImapMessage(
            uid: $uid, uidValidity: 77, mailbox: 'INBOX', messageId: 'fixture-'.$uid,
            inReplyTo: null, references: [], fromName: '', fromEmail: '', to: [], cc: [],
            date: null, subject: '', flags: [], labels: [], textBody: 'fixture',
            htmlBody: null, rawHeaders: [], attachments: [],
        );
    }
}

class UidSearchTestClient extends Client
{
    // PHPUnit clears mocked disconnect() behavior before destruction; the real
    // Client destructor would then create recursive auto-generated return stubs.
    public function __destruct() {}
}

final class UidSearchRecordingProtocol extends ImapProtocol
{
    /** @var list<string> */
    public array $commands = [];

    public function __construct(private readonly array $searchResults) {}

    public function __destruct() {}

    public function requestAndResponse(string $command, array $tokens = [], bool $dontParse = false): Response
    {
        $this->commands[] = $command.' '.implode(' ', $tokens);

        return Response::empty()->setResult([['SEARCH', ...$this->searchResults]]);
    }
}
