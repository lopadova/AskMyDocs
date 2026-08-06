<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * v8.29 — the "Export parameters" surface for a connector account. Proves the
 * export is SECRET-FREE (the vault is never read, secret schema fields are
 * dropped), carries the non-secret connection params + settings, and is
 * tenant-scoped (R30). Covers both the HTTP endpoint and the `connectors:export`
 * CLI (R44 — same core, two surfaces).
 */
final class ConnectorConfigExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_export_returns_non_secret_params_and_settings(): void
    {
        $installation = $this->seedImapInstallation();

        $response = $this->actingAs($this->superAdmin())
            ->getJson("/api/admin/connectors/{$installation->id}/export")
            ->assertOk();

        $data = $response->json('data');

        // Envelope + identity.
        $this->assertSame('askmydocs.connector-config', $data['_meta']['format']);
        $this->assertSame(1, $data['_meta']['version']);
        $this->assertSame('imap', $data['connector']);
        $this->assertSame('Date', $data['label']);
        $this->assertSame('support-mailbox', $data['project_key']);

        // FLAT, credential-form-keyed params (the import prefill shape).
        $this->assertSame('imap.example.com', $data['params']['host']);
        $this->assertSame(993, $data['params']['port']);
        $this->assertSame('ssl', $data['params']['encryption']);
        $this->assertSame('alice@example.com', $data['params']['username']);
        $this->assertSame('basic', $data['params']['auth_mode']);

        // Sync settings ride along (nested, non-secret by construction).
        $this->assertSame(90, $data['settings']['date_window_days']);

        // The importer is told which secret fields it must re-enter.
        $this->assertContains('password', $data['secret_fields_omitted']);
    }

    public function test_export_never_includes_the_secret(): void
    {
        $installation = $this->seedImapInstallation();
        // Vault the password so a naive export that read the vault would leak it.
        app(OAuthCredentialVault::class)->setCredentials(
            $installation->id,
            accessToken: 'super-secret-pw',
            extra: ['auth_mode' => 'basic'],
        );

        $response = $this->actingAs($this->superAdmin())
            ->getJson("/api/admin/connectors/{$installation->id}/export")
            ->assertOk();

        // R14/security — the password appears NOWHERE in the response body, and no
        // `password` key is present under params.
        $this->assertStringNotContainsString('super-secret-pw', $response->getContent());
        $this->assertArrayNotHasKey('password', (array) $response->json('data.params'));
    }

    public function test_export_drops_a_secret_named_key_even_if_it_leaked_into_config_json(): void
    {
        // Defence in depth: a connector that (wrongly) inlined a secret into
        // config_json must still never export it — the schema-driven filter drops
        // every secret field by name regardless of where its value sits.
        $installation = $this->seedImapInstallation(configOverrides: [
            'password' => 'leaked-into-config',
            'connection' => ['host' => 'imap.example.com', 'username' => 'alice@example.com', 'password' => 'also-leaked'],
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->getJson("/api/admin/connectors/{$installation->id}/export")
            ->assertOk();

        $this->assertStringNotContainsString('leaked-into-config', $response->getContent());
        $this->assertArrayNotHasKey('password', (array) $response->json('data.params'));
    }

    public function test_export_survives_a_non_array_connection_in_config(): void
    {
        // A legacy/corrupted config_json whose `connection` is not an array must not
        // 500 the export — it returns the fields that ARE present, secret-free.
        $installation = $this->seedImapInstallation(configOverrides: ['connection' => 'corrupted']);

        $response = $this->actingAs($this->superAdmin())
            ->getJson("/api/admin/connectors/{$installation->id}/export")
            ->assertOk();

        // The connection-target fields are simply absent; the top-level ones remain.
        $this->assertSame('basic', $response->json('data.params.auth_mode'));
        $this->assertArrayNotHasKey('host', (array) $response->json('data.params'));
    }

    public function test_export_is_scoped_to_the_active_tenant(): void
    {
        // An installation owned by a DIFFERENT tenant must not be exportable from
        // the active tenant — 404, never a cross-tenant leak (R30).
        $foreign = ConnectorInstallation::create([
            'tenant_id' => 'tenant-foreign',
            'connector_name' => 'imap',
            'label' => 'Foreign',
            'config_json' => ['auth_mode' => 'basic', 'connection' => ['host' => 'foreign.example.com', 'username' => 'x@foreign.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);

        $this->actingAs($this->superAdmin())
            ->getJson("/api/admin/connectors/{$foreign->id}/export")
            ->assertNotFound();
    }

    public function test_export_forbidden_for_a_viewer(): void
    {
        // R32 — the export lives in the can:manageConnectors group; a viewer is 403.
        $installation = $this->seedImapInstallation();

        $viewer = User::create([
            'name' => 'Vic', 'email' => 'viewer-'.uniqid().'@demo.local', 'password' => bcrypt('secret-password'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)
            ->getJson("/api/admin/connectors/{$installation->id}/export")
            ->assertForbidden();
    }

    public function test_export_cli_prints_secret_free_json(): void
    {
        $installation = $this->seedImapInstallation();
        app(OAuthCredentialVault::class)->setCredentials(
            $installation->id, accessToken: 'cli-secret-pw', extra: ['auth_mode' => 'basic'],
        );

        $this->artisan('connectors:export', ['installation' => $installation->id, '--tenant' => 'test-tenant'])
            ->assertSuccessful()
            ->expectsOutputToContain('imap.example.com')
            ->doesntExpectOutputToContain('cli-secret-pw');
    }

    public function test_export_cli_fails_loudly_for_an_unknown_installation(): void
    {
        $this->artisan('connectors:export', ['installation' => 999999, '--tenant' => 'test-tenant'])
            ->assertFailed();
    }

    /**
     * @param  array<string,mixed>  $configOverrides
     */
    private function seedImapInstallation(array $configOverrides = []): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => 'test-tenant',
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
                'date_window_days' => 90,
            ], $configOverrides),
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::create([
            'name' => 'Root',
            'email' => 'root-'.uniqid().'@demo.local',
            'password' => bcrypt('secret-password'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }
}
