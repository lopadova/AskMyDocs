<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo;

use App\Services\Demo\ImapUidBatchPurger;
use App\Services\Demo\EmailSeedLockLease;
use App\Services\Demo\MailboxTarget;
use App\Services\Demo\PreparedEmailMessage;
use App\Services\Demo\WebklexMailboxAppender;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

final class WebklexMailboxAppenderTest extends TestCase
{
    public function test_multiple_appends_reuse_one_live_protocol_without_timeout_reconnects(): void
    {
        $clients = $this->createMock(ClientManager::class);
        $connection = $this->createMock(ProtocolInterface::class);
        $folder = $this->createStub(Folder::class);
        $folder->path = 'AskMyDocs/Rotta/Operations';
        $client = new RecordingWebklexClient($connection, $folder);
        $success = Response::empty()->setResult(true);
        $appendCalls = [];

        $clients->expects($this->once())
            ->method('make')
            ->with($this->callback(
                static fn (array $config): bool => $config['timeout'] === 30
                    && $config['protocol'] === 'imap',
            ))
            ->willReturn($client);
        $connection->expects($this->exactly(2))
            ->method('appendMessage')
            ->willReturnCallback(function (
                string $folderPath,
                string $raw,
                ?array $flags,
                ?string $date,
            ) use (&$appendCalls, $success): Response {
                $appendCalls[] = [$folderPath, $raw, $flags, $date];

                return $success;
            });

        $appender = new WebklexMailboxAppender(
            $clients,
            new ImapUidBatchPurger,
        );
        $result = $appender->appendStream(
            $this->target(),
            [
                $this->message(1, 'raw-one'),
                $this->message(2, 'raw-two'),
            ],
            lease: EmailSeedLockLease::unlimited(
                static fn (): float => 0.0,
                'rotta-logistics-1',
            ),
        );

        self::assertSame(2, $result->appended);
        self::assertSame(0, $result->alreadyPresent);
        self::assertSame(1, $client->connectCalls);
        self::assertSame(1, $client->folderCalls);
        self::assertSame(1, $client->connectionCalls);
        self::assertSame(0, $client->setTimeoutCalls);
        self::assertSame(0, $client->reconnectCalls);
        self::assertSame(1, $client->disconnectCalls);
        self::assertSame([
            ['AskMyDocs/Rotta/Operations', 'raw-one', null, '01-Jul-2026 10:00:00 +0000'],
            ['AskMyDocs/Rotta/Operations', 'raw-two', null, '01-Jul-2026 10:00:00 +0000'],
        ], $appendCalls);
    }

    public function test_transient_append_drop_refreshes_protocol_before_retry(): void
    {
        $clients = $this->createMock(ClientManager::class);
        $firstConnection = $this->createMock(ProtocolInterface::class);
        $secondConnection = $this->createMock(ProtocolInterface::class);
        $firstFolder = $this->createStub(Folder::class);
        $secondFolder = $this->createMock(Folder::class);
        $query = $this->createMock(WhereQuery::class);
        $firstFolder->path = 'AskMyDocs/Rotta/Operations';
        $secondFolder->path = 'AskMyDocs/Rotta/Operations';
        $firstClient = new RecordingWebklexClient($firstConnection, $firstFolder);
        $secondClient = new RecordingWebklexClient($secondConnection, $secondFolder);
        $success = Response::empty()->setResult(true);
        $stored = [];

        $clients->expects($this->exactly(2))
            ->method('make')
            ->willReturnOnConsecutiveCalls($firstClient, $secondClient);
        $firstConnection->expects($this->once())
            ->method('appendMessage')
            ->willThrowException(new RuntimeException('connection reset by peer'));
        $secondFolder->expects($this->once())
            ->method('query')
            ->willReturn($query);
        $query->expects($this->once())
            ->method('whereMessageId')
            ->with('fixture-1@example.test')
            ->willReturnSelf();
        $query->expects($this->once())
            ->method('setFetchBody')
            ->with(false)
            ->willReturnSelf();
        $query->expects($this->once())
            ->method('leaveUnread')
            ->willReturnSelf();
        $query->expects($this->once())
            ->method('limit')
            ->with(1)
            ->willReturnSelf();
        $query->expects($this->once())
            ->method('get')
            ->willReturn(new MessageCollection);
        $secondConnection->expects($this->once())
            ->method('appendMessage')
            ->with(
                'AskMyDocs/Rotta/Operations',
                'raw-one',
                null,
                '01-Jul-2026 10:00:00 +0000',
            )
            ->willReturn($success);

        $result = (new WebklexMailboxAppender(
            $clients,
            new ImapUidBatchPurger,
        ))->appendStream(
            $this->target(),
            [$this->message(1, 'raw-one')],
            static function (PreparedEmailMessage $message, bool $alreadyPresent) use (&$stored): void {
                $stored[] = [$message->sequence, $alreadyPresent];
            },
        );

        self::assertSame(1, $result->appended);
        self::assertSame(0, $result->alreadyPresent);
        self::assertSame([[1, false]], $stored);
        self::assertSame(1, $firstClient->connectCalls);
        self::assertSame(1, $firstClient->disconnectCalls);
        self::assertSame(1, $secondClient->connectCalls);
        self::assertSame(1, $secondClient->connectionCalls);
        self::assertSame(1, $secondClient->disconnectCalls);
    }

    public function test_ambiguous_drop_found_on_reconnect_uses_new_protocol_for_next_message(): void
    {
        $clients = $this->createMock(ClientManager::class);
        $firstConnection = $this->createMock(ProtocolInterface::class);
        $secondConnection = $this->createMock(ProtocolInterface::class);
        $firstFolder = $this->createStub(Folder::class);
        $secondFolder = $this->createMock(Folder::class);
        $query = $this->createMock(WhereQuery::class);
        $firstFolder->path = 'AskMyDocs/Rotta/Operations';
        $secondFolder->path = 'AskMyDocs/Rotta/Operations';
        $firstClient = new RecordingWebklexClient($firstConnection, $firstFolder);
        $secondClient = new RecordingWebklexClient($secondConnection, $secondFolder);
        $success = Response::empty()->setResult(true);
        $stored = [];

        $clients->expects($this->exactly(2))
            ->method('make')
            ->willReturnOnConsecutiveCalls($firstClient, $secondClient);
        $firstConnection->expects($this->once())
            ->method('appendMessage')
            ->willThrowException(new RuntimeException('connection reset by peer'));
        $secondFolder->expects($this->once())
            ->method('query')
            ->willReturn($query);
        $query->expects($this->once())
            ->method('whereMessageId')
            ->with('fixture-1@example.test')
            ->willReturnSelf();
        $query->expects($this->once())
            ->method('setFetchBody')
            ->with(false)
            ->willReturnSelf();
        $query->expects($this->once())
            ->method('leaveUnread')
            ->willReturnSelf();
        $query->expects($this->once())
            ->method('limit')
            ->with(1)
            ->willReturnSelf();
        $query->expects($this->once())
            ->method('get')
            ->willReturn(new MessageCollection([new \stdClass]));
        $secondConnection->expects($this->once())
            ->method('appendMessage')
            ->with(
                'AskMyDocs/Rotta/Operations',
                'raw-two',
                null,
                '01-Jul-2026 10:00:00 +0000',
            )
            ->willReturn($success);

        $result = (new WebklexMailboxAppender(
            $clients,
            new ImapUidBatchPurger,
        ))->appendStream(
            $this->target(),
            [
                $this->message(1, 'raw-one'),
                $this->message(2, 'raw-two'),
            ],
            static function (PreparedEmailMessage $message, bool $alreadyPresent) use (&$stored): void {
                $stored[] = [$message->sequence, $alreadyPresent];
            },
        );

        self::assertSame(1, $result->appended);
        self::assertSame(1, $result->alreadyPresent);
        self::assertSame([[1, true], [2, false]], $stored);
        self::assertSame(1, $firstClient->connectionCalls);
        self::assertSame(1, $secondClient->connectionCalls);
        self::assertSame(1, $secondClient->disconnectCalls);
    }

    private function target(): MailboxTarget
    {
        return new MailboxTarget(
            mailboxKey: 'rotta-logistics-1',
            projectKey: 'rotta-logistics',
            companyName: 'Rotta Logistics',
            email: 'demo@example.test',
            host: 'imap.example.test',
            port: 993,
            encryption: 'ssl',
            validateCert: true,
            secret: 'secret',
            folder: 'AskMyDocs/Rotta/Operations',
        );
    }

    private function message(int $sequence, string $raw): PreparedEmailMessage
    {
        return new PreparedEmailMessage(
            raw: $raw,
            internalDate: new DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            fixtureId: "fixture-{$sequence}",
            messageId: "<fixture-{$sequence}@example.test>",
            sequence: $sequence,
            subject: "Subject {$sequence}",
            datasetVersion: 'dataset-v1',
        );
    }
}

/**
 * Small stateful client double whose empty destructor avoids Webklex's
 * production disconnect side effect after PHPUnit has verified the test.
 */
final class RecordingWebklexClient extends Client
{
    public int $connectCalls = 0;

    public int $disconnectCalls = 0;

    public int $folderCalls = 0;

    public int $connectionCalls = 0;

    public int $setTimeoutCalls = 0;

    public int $reconnectCalls = 0;

    public function __construct(
        private readonly ProtocolInterface $recordingConnection,
        private readonly Folder $recordingFolder,
    ) {}

    public function __destruct() {}

    public function connect(): Client
    {
        $this->connectCalls++;

        return $this;
    }

    public function disconnect(): Client
    {
        $this->disconnectCalls++;

        return $this;
    }

    public function reconnect(): void
    {
        $this->reconnectCalls++;
    }

    public function getFolderByPath(
        $folder_path,
        bool $utf7 = false,
        bool $soft_fail = false,
    ): ?Folder {
        Assert::assertSame('AskMyDocs/Rotta/Operations', $folder_path);
        Assert::assertFalse($utf7);
        Assert::assertTrue($soft_fail);
        $this->folderCalls++;

        return $this->recordingFolder;
    }

    public function getConnection(): ProtocolInterface
    {
        $this->connectionCalls++;

        return $this->recordingConnection;
    }

    public function setTimeout(int $timeout): ProtocolInterface
    {
        $this->setTimeoutCalls++;

        return $this->recordingConnection;
    }
}
