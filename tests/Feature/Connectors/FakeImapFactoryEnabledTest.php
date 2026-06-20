<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Testing\FakeImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Tests\TestCase;

/**
 * v8.17 — R43 ON state of the offline IMAP seam: with CONNECTOR_IMAP_FAKE_PING
 * flipped on (set before boot so AppServiceProvider::registerFakeImapFactory sees
 * it), the container MUST resolve the fake factory — proving the boot-time swap
 * actually fires. The OFF state lives in {@see FakeImapFactoryBindingTest}.
 */
final class FakeImapFactoryEnabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Set AFTER the base sets the whole `connectors` array, BEFORE boot.
        $app['config']->set('connectors.fake_imap_ping', true);
    }

    public function test_fake_factory_is_bound_when_the_flag_is_on(): void
    {
        $this->assertTrue((bool) config('connectors.fake_imap_ping'));

        $this->assertInstanceOf(
            FakeImapClientFactory::class,
            $this->app->make(ImapClientFactoryInterface::class),
        );
    }
}
