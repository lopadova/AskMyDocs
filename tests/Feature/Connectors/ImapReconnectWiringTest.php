<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\ReconnectingImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Tests\TestCase;

/**
 * R43 (ON state) — with `connectors.imap.reconnect.enabled` on, the host `extend()`
 * wraps the resolved IMAP factory so every produced client absorbs a transient
 * transport drop with one close-and-retry. The default test env pins the flag OFF
 * ({@see ImapReconnectDisabledTest} covers that branch); serialization stays OFF
 * suite-wide, so the reconnect factory is the outermost wrapper here.
 */
final class ImapReconnectWiringTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Flip reconnect ON (phpunit.xml pins it OFF suite-wide).
        $app['config']->set('connectors.imap.reconnect.enabled', true);
    }

    public function test_resolved_imap_factory_is_wrapped_in_the_reconnect_decorator(): void
    {
        $factory = $this->app->make(ImapClientFactoryInterface::class);

        $this->assertInstanceOf(ReconnectingImapClientFactory::class, $factory);
    }
}
