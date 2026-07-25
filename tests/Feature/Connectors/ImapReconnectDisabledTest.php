<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\ProgressTrackingImapClientFactory;
use App\Connectors\Imap\ReconnectingImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use ReflectionProperty;
use Tests\TestCase;

/**
 * R43 (OFF state) — with `connectors.imap.reconnect.enabled` disabled (the default
 * test env, and any deployment that opts out), the host omits only the reconnect
 * layer. UID progress tracking remains the outer observer, with the raw package
 * factory immediately inside it. Proving the OFF branch degrades cleanly (no
 * reconnect layer, no crash) is half the flag's contract.
 */
final class ImapReconnectDisabledTest extends TestCase
{
    public function test_resolved_imap_factory_is_not_wrapped_when_reconnect_is_disabled(): void
    {
        // phpunit.xml sets CONNECTOR_IMAP_RECONNECT_ON_DROP=false suite-wide.
        $this->assertFalse(config('connectors.imap.reconnect.enabled'));

        $factory = $this->app->make(ImapClientFactoryInterface::class);

        $this->assertInstanceOf(ProgressTrackingImapClientFactory::class, $factory);
        $this->assertNotInstanceOf(ReconnectingImapClientFactory::class, $factory);

        $inner = (new ReflectionProperty(ProgressTrackingImapClientFactory::class, 'inner'))
            ->getValue($factory);

        // Serialization is also OFF suite-wide, so tracking wraps the raw factory.
        $this->assertInstanceOf(ImapClientFactory::class, $inner);
    }
}
