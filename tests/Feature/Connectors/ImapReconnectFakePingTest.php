<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\ReconnectingImapClientFactory;
use App\Connectors\Testing\FakeImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Tests\TestCase;

/**
 * E2E-safety guard: when the IMAP is FAKED (CONNECTOR_IMAP_FAKE_PING — the offline
 * seam Playwright/CI use) there is no real server whose drop could be recovered, so
 * the reconnect decorator must NOT wrap the fake factory even with reconnect enabled.
 * Mirrors {@see ImapSerializerFakePingTest} for the serializer.
 */
final class ImapReconnectFakePingTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('connectors.imap.reconnect.enabled', true);
        $app['config']->set('connectors.fake_imap_ping', true);
    }

    public function test_does_not_wrap_the_fake_factory(): void
    {
        $factory = $this->app->make(ImapClientFactoryInterface::class);

        $this->assertNotInstanceOf(ReconnectingImapClientFactory::class, $factory);
        $this->assertInstanceOf(FakeImapClientFactory::class, $factory);
    }
}
