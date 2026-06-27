<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\SerializingImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Tests\TestCase;

/**
 * R43 (ON state) — with `connectors.imap.serialize_connections` enabled, the host
 * `extend()` wraps the resolved IMAP factory in the per-mailbox serializer, so every
 * connection path goes through the lock. The default test env pins the flag OFF
 * ({@see ImapSerializerDisabledTest} covers that branch).
 */
final class ImapSerializerWiringTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Flip the flag ON (phpunit.xml pins it OFF suite-wide) + a lock-capable
        // cache store so the serializer wiring engages.
        $app['config']->set('connectors.imap.serialize_connections', true);
        $app['config']->set('cache.default', 'array');
    }

    public function test_resolved_imap_factory_is_wrapped_in_the_serializer(): void
    {
        $factory = $this->app->make(ImapClientFactoryInterface::class);

        $this->assertInstanceOf(SerializingImapClientFactory::class, $factory);
    }
}
