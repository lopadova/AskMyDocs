<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo;

use App\Services\Demo\ImapUidBatchPurger;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP;

final class ImapUidBatchPurgerTest extends TestCase
{
    public function test_stores_contiguous_ranges_and_reports_only_after_expunge(): void
    {
        $events = [];
        $connection = new RecordingUidExpungeProtocol($events);

        $deleted = (new ImapUidBatchPurger)->purge(
            $connection,
            'AskMyDocs/Rotta/Operations',
            [11, 5, 6, 8, 10, 12, 11],
            onPurged: function (int $count) use (&$events): void {
                $events[] = "callback:{$count}";
            },
        );

        self::assertSame(6, $deleted);
        self::assertSame([
            'capability',
            'select',
            'store:5:6',
            'store:8:8',
            'store:10:12',
            'uid-expunge:5:6,8,10:12',
            'callback:6',
        ], $events);
    }

    public function test_failed_expunge_never_reports_the_page_as_purged(): void
    {
        $failedExpunge = $this->createMock(Response::class);
        $failedExpunge->expects($this->once())
            ->method('validatedData')
            ->willThrowException(new RuntimeException('Injected UID EXPUNGE failure.'));
        $events = [];
        $connection = new RecordingUidExpungeProtocol($events, $failedExpunge);
        $reported = false;

        try {
            (new ImapUidBatchPurger)->purge(
                $connection,
                'AskMyDocs/Rotta/Operations',
                [42],
                onPurged: static function () use (&$reported): void {
                    $reported = true;
                },
            );
            self::fail('Il fallimento di EXPUNGE deve essere propagato.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected UID EXPUNGE failure.', $exception->getMessage());
        }

        self::assertFalse($reported);
        self::assertSame([
            'capability',
            'select',
            'store:42:42',
            'uid-expunge:42',
        ], $events);
    }

    public function test_missing_uidplus_fails_before_marking_any_message_deleted(): void
    {
        $events = [];
        $connection = new RecordingUidExpungeProtocol(
            $events,
            capabilities: ['IMAP4REV1'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non supporta UIDPLUS');

        try {
            (new ImapUidBatchPurger)->purge(
                $connection,
                'AskMyDocs/Rotta/Operations',
                [42],
            );
        } finally {
            self::assertSame(['capability'], $events);
        }
    }

    public function test_uidplus_capability_is_checked_once_per_live_protocol(): void
    {
        $events = [];
        $connection = new RecordingUidExpungeProtocol($events);
        $purger = new ImapUidBatchPurger;

        $purger->purge($connection, 'AskMyDocs/Rotta/Operations', [41]);
        $purger->purge($connection, 'AskMyDocs/Rotta/Operations', [42]);

        self::assertSame(1, array_count_values($events)['capability']);
    }
}

/**
 * IMAP protocol double that records the destructive commands while keeping
 * Webklex's network constructor and destructor completely out of unit tests.
 */
final class RecordingUidExpungeProtocol extends ImapProtocol
{
    private Response $success;

    /**
     * @param  list<string>  $events
     */
    public function __construct(
        private array &$events,
        private readonly ?Response $uidExpungeResponse = null,
        private readonly array $capabilities = ['IMAP4REV1', 'UIDPLUS'],
    ) {
        $this->success = Response::empty()->setResult(true);
    }

    public function __destruct() {}

    public function getCapabilities(): Response
    {
        $this->events[] = 'capability';

        return Response::empty()->setResult($this->capabilities);
    }

    public function selectFolder(string $folder = 'INBOX'): Response
    {
        Assert::assertSame('AskMyDocs/Rotta/Operations', $folder);
        $this->events[] = 'select';

        return $this->success;
    }

    public function store(
        array|string $flags,
        int $from,
        ?int $to = null,
        ?string $mode = null,
        bool $silent = true,
        int|string $uid = IMAP::ST_UID,
        ?string $item = null,
    ): Response {
        Assert::assertSame(['\\Deleted'], $flags);
        Assert::assertSame('+', $mode);
        Assert::assertTrue($silent);
        Assert::assertSame(IMAP::ST_UID, $uid);
        Assert::assertNull($item);
        $this->events[] = "store:{$from}:{$to}";

        return $this->success;
    }

    public function requestAndResponse(
        string $command,
        array $tokens = [],
        bool $dontParse = false,
    ): Response {
        Assert::assertSame('UID EXPUNGE', $command);
        Assert::assertCount(1, $tokens);
        Assert::assertFalse($dontParse);
        $this->events[] = 'uid-expunge:'.$tokens[0];

        return $this->uidExpungeResponse ?? $this->success;
    }
}
