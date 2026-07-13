<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\ReconnectingImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Tests\TestCase;

/**
 * R43 (OFF state) — with `connectors.imap.reconnect.enabled` disabled (the default
 * test env, and any deployment that opts out), the host does NOT wrap the IMAP
 * factory: the raw package factory resolves and connections behave exactly as before.
 * Proving the OFF branch degrades cleanly (raw factory, no crash) is half the flag's
 * contract.
 */
final class ImapReconnectDisabledTest extends TestCase
{
    public function test_resolved_imap_factory_is_not_wrapped_when_reconnect_is_disabled(): void
    {
        // phpunit.xml sets CONNECTOR_IMAP_RECONNECT_ON_DROP=false suite-wide.
        $this->assertFalse(config('connectors.imap.reconnect.enabled'));

        $factory = $this->app->make(ImapClientFactoryInterface::class);

        $this->assertNotInstanceOf(ReconnectingImapClientFactory::class, $factory);
        // The raw package factory is what resolves (serialization is also OFF suite-wide).
        $this->assertInstanceOf(ImapClientFactory::class, $factory);
    }
}
