<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillClient;
use App\Connectors\Imap\Backfill\ImapBackfillClientFactory;
use App\Connectors\Imap\Backfill\ImapBackfillMessageSizer;
use App\Connectors\Imap\ReconnectingImapBackfillClient;
use App\Connectors\Imap\ReconnectingImapClient;
use App\Connectors\Imap\SerializingImapBackfillClient;
use App\Connectors\Imap\SerializingImapClient;
use App\Connectors\Imap\SerializingImapClientFactory;
use Mockery;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The load-bearing composition invariant (production default: both decorators ON):
 * the reconnect layer must sit INSIDE the serialization lock, i.e. the produced
 * client is `SerializingImapClient(ReconnectingImapClient(rawClient))`. That ordering
 * is what keeps a close-and-reconnect happening on the SAME mailbox we already hold
 * the lock for — never releasing the cross-tenant lock between the drop and the retry,
 * never opening a second simultaneous connection. `$app->extend` registers reconnect
 * BEFORE the serializer so it lands innermost; this test pins that so a future
 * re-ordering can't silently invert it.
 */
final class ImapDecoratorCompositionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Production shape: both layers on + a lock-capable store for the serializer.
        $app['config']->set('connectors.imap.reconnect.enabled', true);
        $app['config']->set('connectors.imap.serialize_connections', true);
        $app['config']->set('cache.default', 'array');
    }

    public function test_reconnect_is_nested_inside_the_serialization_lock(): void
    {
        $factory = $this->app->make(ImapClientFactoryInterface::class);
        $this->assertInstanceOf(SerializingImapClientFactory::class, $factory);

        // A keyable connection → the serializer decorates it (an unkeyable one would
        // pass through undecorated, per SerializingImapClientFactory).
        $client = $factory->make(['host' => 'imap.x.test', 'port' => 993, 'username' => 'u@x.test'], 's', 'basic');
        $this->assertInstanceOf(SerializingImapClient::class, $client);

        $inner = (new ReflectionProperty(SerializingImapClient::class, 'inner'))->getValue($client);
        $this->assertInstanceOf(
            ReconnectingImapClient::class,
            $inner,
            'reconnect must be nested INSIDE the serialization lock (Serializing(Reconnecting(raw)))',
        );
    }

    public function test_backfill_bulk_client_uses_the_same_reconnect_and_serialization_chain(): void
    {
        $factory = $this->app->make(ImapClientFactoryInterface::class);
        $this->assertInstanceOf(ImapBackfillClientFactory::class, $factory);

        $client = $factory->makeBackfill(
            ['host' => 'imap.x.test', 'port' => 993, 'username' => 'u@x.test'],
            's',
            'basic',
        );
        $this->assertInstanceOf(SerializingImapBackfillClient::class, $client);

        $inner = (new ReflectionProperty(SerializingImapBackfillClient::class, 'inner'))->getValue($client);
        $this->assertInstanceOf(
            ReconnectingImapBackfillClient::class,
            $inner,
            'backfill reconnect must remain inside the shared per-mailbox lock',
        );
    }

    public function test_metadata_sizing_retries_inside_the_same_mailbox_lock(): void
    {
        $raw = Mockery::mock(ImapBackfillClient::class.', '.ImapBackfillMessageSizer::class);
        $calls = 0;
        $raw->shouldReceive('messageSizes')->twice()->with('INBOX', [7])->andReturnUsing(function () use (&$calls): array {
            if (++$calls === 1) {
                throw new \RuntimeException('connection reset');
            }

            return [7 => 33189313];
        });
        $raw->shouldReceive('close')->twice();
        $store = $this->app['cache']->store('array')->getStore();
        $client = new SerializingImapBackfillClient(
            new ReconnectingImapBackfillClient($raw, 2, 0), $store, 'sizing-lock', 0, 60,
        );

        $this->assertSame([7 => 33189313], $client->messageSizes('INBOX', [7]));
        $other = $store->lock('sizing-lock', 60);
        $this->assertFalse($other->get(), 'a reconnect must keep the physical mailbox locked');
        $client->close();
        $this->assertTrue($other->get());
        $other->release();
    }
}
