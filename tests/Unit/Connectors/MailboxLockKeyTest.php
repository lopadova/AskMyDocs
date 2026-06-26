<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\MailboxLockKey;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * The mailbox lock key identifies a PHYSICAL account (host+port+username) so two
 * connections to the same mailbox serialize — even across tenants / labels.
 */
final class MailboxLockKeyTest extends TestCase
{
    public function test_same_account_on_different_tenants_yields_the_same_key(): void
    {
        // The whole point: cross-tenant. Two installations (different tenants,
        // different labels) on the SAME Gmail account must collide on one key.
        $a = MailboxLockKey::forConnection(['host' => 'imap.gmail.com', 'port' => 993, 'username' => 'ops@acme.test']);
        $b = MailboxLockKey::forConnection(['host' => 'imap.gmail.com', 'port' => 993, 'username' => 'ops@acme.test']);

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertStringStartsWith('imap-mailbox:', (string) $a);
    }

    public function test_host_and_username_are_case_insensitive_and_trimmed(): void
    {
        $a = MailboxLockKey::forConnection(['host' => 'IMAP.Gmail.com', 'port' => 993, 'username' => 'OPS@Acme.test']);
        $b = MailboxLockKey::forConnection(['host' => ' imap.gmail.com ', 'port' => 993, 'username' => ' ops@acme.test ']);

        $this->assertSame($a, $b);
    }

    public function test_port_is_part_of_the_identity_and_defaults_to_993(): void
    {
        $explicit = MailboxLockKey::forConnection(['host' => 'imap.x.test', 'port' => 993, 'username' => 'u@x.test']);
        $defaulted = MailboxLockKey::forConnection(['host' => 'imap.x.test', 'username' => 'u@x.test']);
        $other = MailboxLockKey::forConnection(['host' => 'imap.x.test', 'port' => 143, 'username' => 'u@x.test']);

        // Omitted port materialises to 993 → collides with an explicit 993.
        $this->assertSame($explicit, $defaulted);
        // A different port is a different endpoint → a different key.
        $this->assertNotSame($explicit, $other);
    }

    public function test_returns_null_when_host_or_username_is_absent(): void
    {
        $this->assertNull(MailboxLockKey::forConnection([]));
        $this->assertNull(MailboxLockKey::forConnection(['host' => 'imap.x.test']));
        $this->assertNull(MailboxLockKey::forConnection(['username' => 'u@x.test']));
        $this->assertNull(MailboxLockKey::forConnection(['host' => '  ', 'username' => 'u@x.test']));
    }

    public function test_for_installation_reads_the_connection_block(): void
    {
        $installation = new ConnectorInstallation([
            'config_json' => ['connection' => ['host' => 'imap.x.test', 'port' => 993, 'username' => 'u@x.test']],
        ]);

        $this->assertSame(
            MailboxLockKey::forConnection(['host' => 'imap.x.test', 'port' => 993, 'username' => 'u@x.test']),
            MailboxLockKey::forInstallation($installation),
        );
        $this->assertNull(MailboxLockKey::forInstallation(null));
    }
}
