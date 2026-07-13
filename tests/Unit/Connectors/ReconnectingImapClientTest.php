<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\ReconnectingImapClient;
use App\Connectors\Imap\ReconnectingImapClientFactory;
use Carbon\Carbon;
use ErrorException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Tests\TestCase;

/**
 * The host reconnect decorator: on a TRANSIENT transport drop (the classic
 * "fwrite(): SSL: Broken pipe" the sync used to hard-fail on) it drops the dead
 * socket via the inner client's close() and retries the SAME operation on a fresh
 * connection. Auth failures and programming faults are never retried; a persistent
 * drop is rethrown after the attempt budget so the job-level retry takes over.
 */
final class ReconnectingImapClientTest extends TestCase
{
    public function test_retries_a_transient_drop_and_succeeds_on_reconnect(): void
    {
        // First call drops (broken pipe), the retry succeeds.
        $inner = new ScriptedImapClient([new ErrorException('fwrite(): SSL: Broken pipe')]);
        $client = new ReconnectingImapClient($inner, maxAttempts: 2, retryDelayMs: 0);

        $this->assertSame(['INBOX', 'Sent'], $client->listMailboxes());
        $this->assertSame(2, $inner->calls, 'the operation must be attempted twice (drop → retry)');
        $this->assertSame(1, $inner->closeCount, 'the dead socket must be dropped once before the retry');
    }

    public function test_gives_up_after_max_attempts_and_rethrows_the_drop(): void
    {
        // The drop persists across both attempts → the caller still sees it, so the
        // job-level retry/backoff (not an infinite in-process loop) handles it.
        $inner = new ScriptedImapClient([
            new ErrorException('fwrite(): SSL: Broken pipe'),
            new ErrorException('fwrite(): SSL: Broken pipe'),
        ]);
        $client = new ReconnectingImapClient($inner, maxAttempts: 2, retryDelayMs: 0);

        try {
            $client->ping();
            $this->fail('a persistent drop should propagate after the attempt budget');
        } catch (ErrorException $e) {
            $this->assertStringContainsString('Broken pipe', $e->getMessage());
        }

        $this->assertSame(2, $inner->calls);
        $this->assertSame(1, $inner->closeCount, 'exactly one reconnect between the two attempts');
    }

    public function test_never_retries_an_auth_failure(): void
    {
        // Rejected credentials are permanent — reconnecting cannot fix them, and the
        // host must re-prompt. Propagate on the first throw, no reconnect.
        $inner = new ScriptedImapClient([new ConnectorAuthException('IMAP authentication failed')]);
        $client = new ReconnectingImapClient($inner, maxAttempts: 3, retryDelayMs: 0);

        $this->expectException(ConnectorAuthException::class);
        try {
            $client->ping();
        } finally {
            $this->assertSame(1, $inner->calls, 'auth failure must not be retried');
            $this->assertSame(0, $inner->closeCount, 'no reconnect on an auth failure');
        }
    }

    public function test_never_retries_a_non_transient_error(): void
    {
        // A real "Mailbox not found" is not a transport drop — a reconnect would just
        // reproduce it. Propagate on the first throw.
        $inner = new ScriptedImapClient([new ConnectorApiException('Mailbox not found: Archive')]);
        $client = new ReconnectingImapClient($inner, maxAttempts: 3, retryDelayMs: 0);

        $this->expectException(ConnectorApiException::class);
        try {
            $client->selectMailbox('Archive');
        } finally {
            $this->assertSame(1, $inner->calls);
            $this->assertSame(0, $inner->closeCount);
        }
    }

    public function test_never_retries_a_programming_fault(): void
    {
        // A \TypeError is a bug, not an outage — it must surface immediately, never be
        // masked by a silent retry.
        $inner = new ScriptedImapClient([new \TypeError('Argument $uid must be int')]);
        $client = new ReconnectingImapClient($inner, maxAttempts: 3, retryDelayMs: 0);

        $this->expectException(\TypeError::class);
        try {
            $client->fetchMessage('INBOX', 1);
        } finally {
            $this->assertSame(1, $inner->calls);
            $this->assertSame(0, $inner->closeCount);
        }
    }

    public function test_a_single_attempt_disables_retry(): void
    {
        // max_attempts=1 means "detect but do not retry" — a drop propagates on the
        // first throw with no reconnect.
        $inner = new ScriptedImapClient([new ErrorException('fwrite(): SSL: Broken pipe')]);
        $client = new ReconnectingImapClient($inner, maxAttempts: 1, retryDelayMs: 0);

        $this->expectException(ErrorException::class);
        try {
            $client->ping();
        } finally {
            $this->assertSame(1, $inner->calls);
            $this->assertSame(0, $inner->closeCount);
        }
    }

    public function test_a_pathological_attempt_budget_is_capped(): void
    {
        // The reconnect runs inside the cross-tenant per-mailbox lock, so a
        // misconfigured huge budget must NOT let a persistent drop hold that lock
        // unboundedly — the attempt budget is hard-capped at 10 regardless of config.
        $inner = new ScriptedImapClient(array_fill(0, 50, new ErrorException('fwrite(): SSL: Broken pipe')));
        $client = new ReconnectingImapClient($inner, maxAttempts: 1000, retryDelayMs: 0);

        try {
            $client->ping();
            $this->fail('a persistent drop should propagate after the capped budget');
        } catch (ErrorException) {
            // expected
        }

        $this->assertSame(10, $inner->calls, 'attempts must be capped at the ceiling (10), not the configured 1000');
        $this->assertSame(9, $inner->closeCount, 'one reconnect between each of the 10 capped attempts');
    }

    public function test_close_passes_through_without_retry_logic(): void
    {
        $inner = new ScriptedImapClient([]);
        $client = new ReconnectingImapClient($inner, maxAttempts: 2, retryDelayMs: 0);

        $client->close();

        $this->assertSame(1, $inner->closeCount);
    }

    public function test_a_recovered_fetch_returns_the_message(): void
    {
        // Value threads back through the retry: the fetched message is returned, not
        // swallowed, once the reconnect succeeds.
        $inner = new ScriptedImapClient([new ErrorException('SSL: Broken pipe')]);
        $client = new ReconnectingImapClient($inner, maxAttempts: 2, retryDelayMs: 0);

        $this->assertSame(7, $client->fetchMessage('INBOX', 7)->uid);
    }

    public function test_factory_wraps_produced_clients(): void
    {
        $factory = new ReconnectingImapClientFactory($this->innerFactory(), maxAttempts: 2, retryDelayMs: 0);

        $client = $factory->make(['host' => 'imap.x.test', 'username' => 'u@x.test'], 's', 'basic');

        $this->assertInstanceOf(ReconnectingImapClient::class, $client);
    }

    private function innerFactory(): ImapClientFactoryInterface
    {
        return new class implements ImapClientFactoryInterface
        {
            public function make(array $connection, string $secret, string $authMode): ImapClientInterface
            {
                return new ScriptedImapClient([]);
            }
        };
    }
}

/**
 * A fake inner client that throws a scripted sequence of exceptions (one popped per
 * operation call) before succeeding, and counts operation + close() calls so a test
 * can prove the reconnect happened (or didn't).
 */
class ScriptedImapClient implements ImapClientInterface
{
    public int $calls = 0;

    public int $closeCount = 0;

    /** @param list<\Throwable> $script */
    public function __construct(private array $script) {}

    public function listMailboxes(): array
    {
        $this->tick();

        return ['INBOX', 'Sent'];
    }

    public function selectMailbox(string $name): MailboxState
    {
        $this->tick();

        return new MailboxState(uidValidity: 1, lastUid: 10);
    }

    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        $this->tick();

        return [1, 2, 3];
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        $this->tick();

        return new ImapMessage(
            uid: $uid,
            uidValidity: 1,
            mailbox: $mailbox,
            messageId: '<m@x>',
            inReplyTo: null,
            references: [],
            fromName: 'X',
            fromEmail: 'x@x.test',
            to: [],
            cc: [],
            date: null,
            subject: 's',
            flags: [],
            labels: [],
            textBody: 'b',
            htmlBody: null,
            rawHeaders: [],
            attachments: [],
        );
    }

    public function ping(): bool
    {
        $this->tick();

        return true;
    }

    public function close(): void
    {
        $this->closeCount++;
    }

    private function tick(): void
    {
        $this->calls++;
        $next = array_shift($this->script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
    }
}
