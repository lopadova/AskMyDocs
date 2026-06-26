<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\MailboxBusyException;
use App\Connectors\Imap\SerializingImapClient;
use App\Connectors\Imap\SerializingImapClientFactory;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\LockProvider;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Tests\TestCase;

/**
 * The per-mailbox serialization decorator: holds an atomic lock for the lifetime of
 * the connection (first use → close), so at most one connection per mailbox is live.
 * Uses the array lock store (a LockProvider) — single-process, enough to prove the
 * acquire/hold/release/contention behaviour deterministically.
 */
final class SerializingImapClientTest extends TestCase
{
    private const KEY = 'imap-mailbox:test';

    public function test_holds_the_lock_while_connected_and_releases_it_on_close(): void
    {
        $store = $this->lockStore();
        $client = new SerializingImapClient(new FakeImapClient, $store, self::KEY, waitSeconds: 0, ttlSeconds: 60);

        // Before any call the mailbox is free.
        $this->assertTrue($this->isFree($store), 'lock should be free before first use');

        $client->ping(); // first connection-triggering call → acquires the lock

        $this->assertFalse($this->isFree($store), 'lock must be HELD while the connection is open');

        $client->close();

        $this->assertTrue($this->isFree($store), 'lock must be RELEASED on close');
    }

    public function test_a_second_call_does_not_reacquire(): void
    {
        $store = $this->lockStore();
        $inner = new FakeImapClient;
        $client = new SerializingImapClient($inner, $store, self::KEY, waitSeconds: 0, ttlSeconds: 60);

        $client->listMailboxes();
        $client->ping(); // still the same single lock — no double acquire / deadlock on self
        $client->fetchMessage('INBOX', 1);

        $this->assertFalse($this->isFree($store));
        $client->close();
        $this->assertTrue($this->isFree($store));
    }

    public function test_releases_the_lock_when_destroyed_without_close_after_a_throw(): void
    {
        // Mirrors the vendor handleOAuthCallback basic-auth check: ping() throws on a
        // bad/expired password and the caller never reaches close() (no finally). The
        // lock must NOT leak until the TTL — __destruct frees it (else a wrong-password
        // install attempt would block the account for every tenant for minutes).
        $store = $this->lockStore();
        $throwing = new class extends FakeImapClient
        {
            public function ping(): bool
            {
                throw new \RuntimeException('IMAP connect failed: bad credentials');
            }
        };
        $client = new SerializingImapClient($throwing, $store, self::KEY, waitSeconds: 0, ttlSeconds: 60);

        try {
            $client->ping();
            $this->fail('ping() should have thrown');
        } catch (\RuntimeException) {
            // expected
        }

        // Acquired before the inner ping() threw; no close() was called → still held.
        $this->assertFalse($this->isFree($store), 'lock is held after a throw with no close()');

        unset($client);
        gc_collect_cycles();

        $this->assertTrue($this->isFree($store), '__destruct must free the otherwise-leaked lock');
    }

    public function test_a_busy_mailbox_throws_mailbox_busy(): void
    {
        $store = $this->lockStore();
        // Another connection already holds the mailbox.
        $this->assertTrue($store->lock(self::KEY, 60)->get());

        $client = new SerializingImapClient(new FakeImapClient, $store, self::KEY, waitSeconds: 0, ttlSeconds: 60);

        $this->expectException(MailboxBusyException::class);
        $client->selectMailbox('INBOX');
    }

    public function test_a_zero_ttl_misconfiguration_is_clamped_and_still_holds(): void
    {
        // A 0 TTL from a misconfigured env would, unclamped, expire the lock the
        // instant it is taken (ArrayStore stores expiry = now + ttl) → no mutual
        // exclusion at all. The clamp (max(1, ttl)) keeps the lock genuinely held.
        $store = $this->lockStore();
        $client = new SerializingImapClient(new FakeImapClient, $store, self::KEY, waitSeconds: -5, ttlSeconds: 0);

        $client->ping();

        $this->assertFalse($this->isFree($store), 'a clamped TTL must keep the lock HELD, not expire immediately');

        $client->close();
        $this->assertTrue($this->isFree($store));
    }

    public function test_delegates_results_to_the_inner_client(): void
    {
        $store = $this->lockStore();
        $client = new SerializingImapClient(new FakeImapClient, $store, self::KEY, waitSeconds: 0, ttlSeconds: 60);

        $this->assertSame(['INBOX', 'Sent'], $client->listMailboxes());
        $this->assertSame(7, $client->fetchMessage('INBOX', 7)->uid);
        $client->close();
    }

    public function test_factory_decorates_a_keyable_connection_and_passes_through_an_unkeyable_one(): void
    {
        $store = $this->lockStore();
        $factory = new SerializingImapClientFactory($this->innerFactory(), $store, 0, 60);

        $decorated = $factory->make(['host' => 'imap.x.test', 'port' => 993, 'username' => 'u@x.test'], 's', 'basic');
        $this->assertInstanceOf(SerializingImapClient::class, $decorated);

        // No host/username → nothing to serialize on → the raw inner client.
        $passthrough = $factory->make([], 's', 'basic');
        $this->assertInstanceOf(FakeImapClient::class, $passthrough);
    }

    private function lockStore(): LockProvider
    {
        return new ArrayStore;
    }

    private function isFree(LockProvider $store): bool
    {
        $probe = $store->lock(self::KEY, 60);
        if ($probe->get()) {
            $probe->release();

            return true;
        }

        return false;
    }

    private function innerFactory(): ImapClientFactoryInterface
    {
        return new class implements ImapClientFactoryInterface
        {
            public function make(array $connection, string $secret, string $authMode): ImapClientInterface
            {
                return new FakeImapClient;
            }
        };
    }
}

class FakeImapClient implements ImapClientInterface
{
    public function listMailboxes(): array
    {
        return ['INBOX', 'Sent'];
    }

    public function selectMailbox(string $name): MailboxState
    {
        return new MailboxState(uidValidity: 1, lastUid: 10);
    }

    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        return [1, 2, 3];
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
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
        return true;
    }

    public function close(): void {}
}
