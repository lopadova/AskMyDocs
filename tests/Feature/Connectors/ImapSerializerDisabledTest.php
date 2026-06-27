<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\SerializingImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Tests\TestCase;

/**
 * R43 (OFF state) — with `connectors.imap.serialize_connections` disabled (the
 * default test env, and any deployment that opts out), the host does NOT wrap the
 * IMAP factory: the raw factory resolves and connections behave exactly as before.
 * Proving the OFF branch is healthy is half the flag's contract.
 */
final class ImapSerializerDisabledTest extends TestCase
{
    public function test_resolved_imap_factory_is_not_wrapped_when_serialization_is_disabled(): void
    {
        // phpunit.xml sets CONNECTOR_IMAP_SERIALIZE_CONNECTIONS=false suite-wide.
        $this->assertFalse(config('connectors.imap.serialize_connections'));

        $factory = $this->app->make(ImapClientFactoryInterface::class);

        $this->assertNotInstanceOf(SerializingImapClientFactory::class, $factory);
    }
}
