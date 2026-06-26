<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\SerializingImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Tests\TestCase;

/**
 * E2E-safety guard: when the IMAP is FAKED (CONNECTOR_IMAP_FAKE_PING — the offline
 * seam Playwright/CI use), there is no real server to protect and the test env's
 * cache store may not host locks, so the serializer must NOT wrap the fake factory
 * even with `serialize_connections` enabled.
 */
final class ImapSerializerFakePingTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('connectors.imap.serialize_connections', true);
        $app['config']->set('connectors.fake_imap_ping', true);
    }

    public function test_does_not_wrap_the_fake_factory(): void
    {
        $factory = $this->app->make(ImapClientFactoryInterface::class);

        $this->assertNotInstanceOf(SerializingImapClientFactory::class, $factory);
    }
}
