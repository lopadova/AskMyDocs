<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Tests\TestCase;

/**
 * v8.29 — RE-configure an existing IMAP account's connection parameters (the
 * Edit → Connection tab). Proves the verify-before-keep contract in both secret
 * modes (new password vs keep-current), the rollback on a failed verify (config +
 * vault left intact), that sync-settings are preserved, and tenant isolation (R30)
 * + RBAC (R32). The IMAP server is the ONLY faked boundary (R13).
 */
final class ConnectorReconfigureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        Project::create(['project_key' => 'support-mailbox', 'name' => 'Support Mailbox']);
    }

    public function test_reconfigure_changes_host_and_reauthenticates_with_a_new_password(): void
    {
        $installation = $this->seedActiveImap(vaultPassword: 'old-pw');
        $this->bindImapFactory(pingSucceeds: true);

        $response = $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/connectors/{$installation->id}/reconfigure", [
                'auth_mode' => 'basic',
                'host' => 'imap.new-host.tld',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => true,
                'username' => 'alice@example.com',
                'password' => 'brand-new-pw',
            ])
            ->assertOk();

        $this->assertSame('active', $response->json('data.status'));

        $installation->refresh();
        $this->assertSame('imap.new-host.tld', $installation->config_json['connection']['host']);
        // The new secret is vaulted (re-auth happened), the old one gone.
        $this->assertSame('brand-new-pw', app(OAuthCredentialVault::class)->getAccessToken($installation->id));
        // The secret never appears in config_json or the response.
        $this->assertStringNotContainsString('brand-new-pw', json_encode($installation->config_json));
        $this->assertStringNotContainsString('brand-new-pw', $response->getContent());
    }

    public function test_reconfigure_keeps_the_current_secret_when_password_is_blank(): void
    {
        $installation = $this->seedActiveImap(vaultPassword: 'keep-me-pw');
        $this->bindImapFactory(pingSucceeds: true);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/connectors/{$installation->id}/reconfigure", [
                'auth_mode' => 'basic',
                'host' => 'imap.moved.tld',
                'username' => 'alice@example.com',
                // no password → keep current
            ])
            ->assertOk();

        $installation->refresh();
        $this->assertSame('imap.moved.tld', $installation->config_json['connection']['host']);
        // Port/encryption were NOT submitted → they keep their stored values (PATCH).
        $this->assertSame(993, $installation->config_json['connection']['port']);
        $this->assertSame('ssl', $installation->config_json['connection']['encryption']);
        // The vaulted secret is untouched.
        $this->assertSame('keep-me-pw', app(OAuthCredentialVault::class)->getAccessToken($installation->id));
    }

    public function test_reconfigure_rejects_a_failing_ping_and_rolls_back_config_and_vault(): void
    {
        $installation = $this->seedActiveImap(vaultPassword: 'old-pw');
        $this->bindImapFactory(pingSucceeds: false);

        $response = $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/connectors/{$installation->id}/reconfigure", [
                'auth_mode' => 'basic',
                'host' => 'imap.broken.tld',
                'username' => 'alice@example.com',
                'password' => 'would-be-new-pw',
            ])
            ->assertStatus(422);

        $this->assertNotEmpty($response->json('error'));

        $installation->refresh();
        // R14 — a failed verify leaves the account exactly as it was: the host is
        // rolled back and the vault still holds the ORIGINAL password.
        $this->assertSame('imap.example.com', $installation->config_json['connection']['host']);
        $this->assertSame('old-pw', app(OAuthCredentialVault::class)->getAccessToken($installation->id));
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $installation->status);
    }

    public function test_reconfigure_keep_secret_rolls_back_when_the_new_host_is_unreachable(): void
    {
        // The keep-current-password path also verifies (health ping) before keeping,
        // and rolls the config back on failure — no silent "saved a broken host".
        $installation = $this->seedActiveImap(vaultPassword: 'old-pw');
        $this->bindImapFactory(pingSucceeds: false);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/connectors/{$installation->id}/reconfigure", [
                'auth_mode' => 'basic',
                'host' => 'imap.unreachable.tld',
                'username' => 'alice@example.com',
            ])
            ->assertStatus(422);

        $installation->refresh();
        $this->assertSame('imap.example.com', $installation->config_json['connection']['host']);
    }

    public function test_reconfigure_preserves_the_sync_settings(): void
    {
        $installation = $this->seedActiveImap(vaultPassword: 'old-pw', configOverrides: [
            'folders' => ['include' => ['INBOX', 'Work']],
            'date_window_days' => 120,
        ]);
        $this->bindImapFactory(pingSucceeds: true);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/connectors/{$installation->id}/reconfigure", [
                'auth_mode' => 'basic',
                'host' => 'imap.new.tld',
                'username' => 'alice@example.com',
                'password' => 'new-pw',
            ])
            ->assertOk();

        $installation->refresh();
        // The connection changed but the post-install sync settings survived.
        $this->assertSame('imap.new.tld', $installation->config_json['connection']['host']);
        $this->assertSame(['INBOX', 'Work'], $installation->config_json['folders']['include']);
        $this->assertSame(120, $installation->config_json['date_window_days']);
    }

    public function test_reconfigure_is_scoped_to_the_active_tenant(): void
    {
        $foreign = ConnectorInstallation::create([
            'tenant_id' => 'tenant-foreign',
            'connector_name' => 'imap',
            'label' => 'Foreign',
            'config_json' => ['auth_mode' => 'basic', 'connection' => ['host' => 'foreign.example.com', 'username' => 'x@foreign.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);
        $this->bindImapFactory(pingSucceeds: true);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/connectors/{$foreign->id}/reconfigure", [
                'auth_mode' => 'basic', 'host' => 'evil.tld', 'username' => 'x@foreign.test', 'password' => 'x',
            ])
            ->assertNotFound();

        $foreign->refresh();
        $this->assertSame('foreign.example.com', $foreign->config_json['connection']['host']);
    }

    public function test_reconfigure_forbidden_for_a_viewer(): void
    {
        $installation = $this->seedActiveImap(vaultPassword: 'old-pw');
        $this->bindImapFactory(pingSucceeds: true);

        $viewer = User::create([
            'name' => 'Vic', 'email' => 'viewer-'.uniqid().'@demo.local', 'password' => bcrypt('secret-password'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)
            ->postJson("/api/admin/connectors/{$installation->id}/reconfigure", [
                'auth_mode' => 'basic', 'host' => 'x.tld', 'username' => 'a@b.c', 'password' => 'x',
            ])
            ->assertForbidden();
    }

    public function test_reconfigure_cli_updates_a_connection_param(): void
    {
        $installation = $this->seedActiveImap(vaultPassword: 'old-pw');
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connectors:reconfigure', [
            'installation' => $installation->id,
            '--tenant' => 'default',
            '--set' => ['host=imap.cli-changed.tld'],
        ])->assertSuccessful();

        $installation->refresh();
        $this->assertSame('imap.cli-changed.tld', $installation->config_json['connection']['host']);
        // Kept the current secret (no --set-secret).
        $this->assertSame('old-pw', app(OAuthCredentialVault::class)->getAccessToken($installation->id));
    }

    public function test_reconfigure_cli_rejects_an_unknown_param(): void
    {
        $installation = $this->seedActiveImap(vaultPassword: 'old-pw');
        $this->bindImapFactory(pingSucceeds: true);

        $this->artisan('connectors:reconfigure', [
            'installation' => $installation->id,
            '--tenant' => 'default',
            '--set' => ['nope=whatever'],
        ])->assertFailed();
    }

    public function test_reconfigure_keeps_a_disabled_account_disabled(): void
    {
        // A deliberately-disabled account edited via the Connection tab must NOT be
        // silently re-enabled — only enable() should resume the scheduler.
        $installation = $this->seedActiveImap(vaultPassword: 'old-pw');
        $installation->forceFill(['status' => ConnectorInstallation::STATUS_DISABLED])->save();
        $this->bindImapFactory(pingSucceeds: true);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/connectors/{$installation->id}/reconfigure", [
                'auth_mode' => 'basic',
                'host' => 'imap.moved.tld',
                'username' => 'alice@example.com',
            ])
            ->assertOk();

        $installation->refresh();
        // The connection edit landed, but the status stayed DISABLED.
        $this->assertSame('imap.moved.tld', $installation->config_json['connection']['host']);
        $this->assertSame(ConnectorInstallation::STATUS_DISABLED, $installation->status);
    }

    public function test_reconfigure_cli_works_in_a_non_default_tenant(): void
    {
        // Exercises the host+package TenantContext mirror on the keep-secret path.
        // The keep-secret verify reads the vaulted credential through the PACKAGE
        // context; a SECRET-AWARE fake pings true ONLY when a non-empty secret was
        // resolved. Without the mirror, --tenant=acme leaves the package context on
        // 'default' → getAccessToken(acme installation) returns null → empty secret →
        // the fake ping FAILS → the command would FAIL. With the mirror the acme
        // credential is found and the reconfigure succeeds.
        $host = app(\App\Support\TenantContext::class);
        $package = app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class);

        $host->set('acme');
        $package->set('acme');
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'imap',
            'label' => 'Acme',
            'project_key' => null,
            'config_json' => [
                'auth_mode' => 'basic',
                'connection' => ['host' => 'imap.acme.tld', 'port' => 993, 'encryption' => 'ssl', 'validate_cert' => true, 'username' => 'ops@acme.tld'],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);
        app(OAuthCredentialVault::class)->setCredentials(
            $installation->id, accessToken: 'acme-pw', extra: ['auth_mode' => 'basic'],
        );
        // Reset both contexts to default — the command must re-establish 'acme' itself.
        $host->set('default');
        $package->set('default');

        $this->bindSecretAwareImapFactory();

        $this->artisan('connectors:reconfigure', [
            'installation' => $installation->id,
            '--tenant' => 'acme',
            '--set' => ['host=imap.acme-changed.tld'],
        ])->assertSuccessful();

        $installation->refresh();
        $this->assertSame('imap.acme-changed.tld', $installation->config_json['connection']['host']);
    }

    /**
     * @param  array<string,mixed>  $configOverrides
     */
    private function seedActiveImap(string $vaultPassword, array $configOverrides = []): ConnectorInstallation
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'Date',
            'project_key' => 'support-mailbox',
            'config_json' => array_merge([
                'auth_mode' => 'basic',
                'connection' => [
                    'host' => 'imap.example.com',
                    'port' => 993,
                    'encryption' => 'ssl',
                    'validate_cert' => true,
                    'username' => 'alice@example.com',
                ],
            ], $configOverrides),
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);

        app(OAuthCredentialVault::class)->setCredentials(
            $installation->id,
            accessToken: $vaultPassword,
            refreshToken: null,
            expiresAt: null,
            extra: ['auth_mode' => 'basic'],
        );

        return $installation;
    }

    private function superAdmin(): User
    {
        $user = User::create([
            'name' => 'Root', 'email' => 'root-'.uniqid().'@demo.local', 'password' => bcrypt('secret-password'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function bindImapFactory(bool $pingSucceeds): void
    {
        $client = new class($pingSucceeds) implements ImapClientInterface
        {
            public function __construct(private readonly bool $ok) {}

            public function ping(): bool
            {
                return $this->ok;
            }

            public function close(): void {}

            public function listMailboxes(): array
            {
                return [];
            }

            public function selectMailbox(string $name): MailboxState
            {
                throw new \LogicException('not used in reconfigure');
            }

            public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
            {
                throw new \LogicException('not used in reconfigure');
            }

            public function fetchMessage(string $mailbox, int $uid): ImapMessage
            {
                throw new \LogicException('not used in reconfigure');
            }
        };

        $factory = new class($client) implements ImapClientFactoryInterface
        {
            public function __construct(private readonly ImapClientInterface $client) {}

            public function make(array $connection, string $secret, string $authMode): ImapClientInterface
            {
                return $this->client;
            }
        };

        $this->app->instance(ImapClientFactoryInterface::class, $factory);
        $this->app->forgetInstance(ConnectorRegistry::class);
    }

    /**
     * A fake whose ping succeeds ONLY when make() received a non-empty secret — so a
     * keep-secret verify that couldn't resolve the vaulted credential (e.g. a tenant
     * mismatch) fails the ping instead of a false pass.
     */
    private function bindSecretAwareImapFactory(): void
    {
        $factory = new class implements ImapClientFactoryInterface
        {
            public function make(array $connection, string $secret, string $authMode): ImapClientInterface
            {
                return new class($secret !== '') implements ImapClientInterface
                {
                    public function __construct(private readonly bool $ok) {}

                    public function ping(): bool
                    {
                        return $this->ok;
                    }

                    public function close(): void {}

                    public function listMailboxes(): array
                    {
                        return [];
                    }

                    public function selectMailbox(string $name): MailboxState
                    {
                        throw new \LogicException('not used');
                    }

                    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
                    {
                        throw new \LogicException('not used');
                    }

                    public function fetchMessage(string $mailbox, int $uid): ImapMessage
                    {
                        throw new \LogicException('not used');
                    }
                };
            }
        };

        $this->app->instance(ImapClientFactoryInterface::class, $factory);
        $this->app->forgetInstance(ConnectorRegistry::class);
    }
}
