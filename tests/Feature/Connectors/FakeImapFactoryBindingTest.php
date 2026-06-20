<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Testing\FakeImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Tests\TestCase;

/**
 * v8.17 — R43 OFF state of the offline IMAP seam: with CONNECTOR_IMAP_FAKE_PING
 * at its default (false), the container MUST resolve the REAL package factory.
 * The ON state lives in {@see FakeImapFactoryEnabledTest} (it needs the flag set
 * before boot). Together they prove the default-OFF flag can never ship the fake
 * binding by accident.
 */
final class FakeImapFactoryBindingTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Force OFF so the test is deterministic even if a developer has exported
        // CONNECTOR_IMAP_FAKE_PING=true locally (e.g. to run the IMAP E2E).
        $app['config']->set('connectors.fake_imap_ping', false);
    }

    public function test_real_factory_is_bound_when_the_flag_is_off(): void
    {
        // The base TestCase leaves connectors.fake_imap_ping at its config default.
        $this->assertFalse((bool) config('connectors.fake_imap_ping'));

        $factory = $this->app->make(ImapClientFactoryInterface::class);

        $this->assertInstanceOf(ImapClientFactory::class, $factory);
        $this->assertNotInstanceOf(FakeImapClientFactory::class, $factory);
    }
}
