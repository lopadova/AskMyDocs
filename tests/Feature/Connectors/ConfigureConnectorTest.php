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
 * v8.17 — the credential-connector `configure` flow for IMAP (the first
 * credential-based connector). Covers both auth modes + the OFF/failure paths,
 * and proves the secret is vaulted (never in config_json/response).
 *
 * The IMAP server is the ONLY external boundary: we bind a fake
 * {@see ImapClientFactoryInterface} whose client returns a configurable ping(),
 * then `forgetInstance(ConnectorRegistry::class)` so the eagerly-built connector
 * is rebuilt with the fake factory (the registry instantiates every connector in
 * its constructor).
 */
final class ConfigureConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();

        // v8.20 — the credential `configure` payload binds the account to a real
        // project (R18 exists rule). Seed it in the active (default) tenant.
        Project::create(['project_key' => 'support-mailbox', 'name' => 'Support Mailbox']);
    }

    public function test_index_marks_imap_as_credential_with_a_form_schema(): void
    {
        $this->bindImapFactory(pingSucceeds: true);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/connectors')
            ->assertOk();

        $imap = collect($response->json('data'))->firstWhere('key', 'imap');

        $this->assertNotNull($imap, 'IMAP connector must be auto-discovered and listed.');
        $this->assertSame('credential', $imap['auth_kind']);
        $this->assertIsArray($imap['credential_form_schema']);
        $this->assertNotEmpty($imap['credential_form_schema']);
        // The OAuth connectors must keep auth_kind=oauth + null schema (back-compat).
        $oauth = collect($response->json('data'))->firstWhere('key', 'google-drive');
        $this->assertSame('oauth', $oauth['auth_kind']);
        $this->assertNull($oauth['credential_form_schema']);
    }

    public function test_basic_auth_configure_activates_and_vaults_the_password(): void
    {
        $this->bindImapFactory(pingSucceeds: true);

        $response = $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/configure', $this->basicPayload())
            ->assertOk();

        $this->assertSame('active', $response->json('data.status'));
        $this->assertNull($response->json('data.redirect_to'));

        $installation = ConnectorInstallation::query()
            ->where('tenant_id', 'default')->where('connector_name', 'imap')->firstOrFail();
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $installation->status);

        // config_json carries the non-secret connection metadata — NEVER the password.
        $config = $installation->config_json;
        $this->assertSame('imap.example.com', $config['connection']['host']);
        $this->assertSame('alice@example.com', $config['connection']['username']);
        $this->assertSame('basic', $config['auth_mode']);
        $this->assertArrayNotHasKey('password', $config);
        $this->assertStringNotContainsString('s3cr3t-app-pw', json_encode($config));

        // v8.20 — project binding is a first-class COLUMN, never config_json,
        // and the default label is applied when the payload omits one.
        $this->assertSame('support-mailbox', $installation->project_key);
        $this->assertArrayNotHasKey('project_key', $config);
        $this->assertSame('default', $installation->label);

        // The secret is in the encrypted vault.
        $this->assertSame('s3cr3t-app-pw', app(OAuthCredentialVault::class)->getAccessToken($installation->id));

        // ...and never echoed in the response.
        $this->assertStringNotContainsString('s3cr3t-app-pw', $response->getContent());
    }

    public function test_basic_auth_configure_with_bad_credentials_returns_422_and_rolls_back_the_row(): void
    {
        $this->bindImapFactory(pingSucceeds: false);

        $response = $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/configure', $this->basicPayload())
            ->assertStatus(422);

        // R14 — the failure is still surfaced loudly with the connector's message.
        $this->assertNotEmpty($response->json('error'));

        // The connection test failed, so NOTHING is saved: the pending row created
        // to drive the ping is rolled back. A lingering PENDING row is exactly what
        // used to trip the (tenant, connector, label) unique on the next retry and
        // dead-end the operator on "label already taken".
        $this->assertSame(
            0,
            ConnectorInstallation::query()
                ->where('tenant_id', 'default')->where('connector_name', 'imap')->count(),
            'A failed connection test must leave no connector_installations row behind.',
        );
    }

    public function test_configure_can_retry_the_same_label_after_a_failed_connection_test(): void
    {
        // The exact operator scenario from the bug report: a first attempt fails the
        // IMAP login, the operator fixes a parameter and re-submits with the SAME
        // label. The retry must succeed (the rolled-back first attempt left no row
        // to collide with) and leave exactly ONE active account.
        $admin = $this->superAdmin();

        $this->bindImapFactory(pingSucceeds: false);
        $this->actingAs($admin)
            ->postJson('/api/admin/connectors/imap/configure', $this->basicPayload(['label' => 'Autry']))
            ->assertStatus(422);

        // Correct the credentials and retry with the identical label.
        $this->bindImapFactory(pingSucceeds: true);
        $response = $this->actingAs($admin)
            ->postJson('/api/admin/connectors/imap/configure', $this->basicPayload(['label' => 'Autry']))
            ->assertOk();

        $this->assertSame('active', $response->json('data.status'));

        $rows = ConnectorInstallation::query()
            ->where('tenant_id', 'default')->where('connector_name', 'imap')->get();
        $this->assertCount(1, $rows, 'The retry must not leak a second row.');
        $this->assertSame('Autry', $rows->first()->label);
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $rows->first()->status);
        // The corrected password is now vaulted (the first, failed attempt never was).
        $this->assertSame('s3cr3t-app-pw', app(OAuthCredentialVault::class)->getAccessToken($rows->first()->id));
    }

    public function test_basic_auth_configure_rejects_missing_required_fields(): void
    {
        $this->bindImapFactory(pingSucceeds: true);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/configure', ['auth_mode' => 'basic'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host', 'username', 'password']);
    }

    public function test_omitting_auth_mode_defaults_to_basic_and_still_requires_basic_fields(): void
    {
        $this->bindImapFactory(pingSucceeds: true);
        $admin = $this->superAdmin();

        // auth_mode omitted (relies on the schema default 'basic'). The default
        // is merged before validation, so the basic-only required fields are
        // genuinely enforced — omitting host must 422, not silently pass.
        $this->actingAs($admin)
            ->postJson('/api/admin/connectors/imap/configure', [
                'username' => 'alice@example.com',
                'password' => 's3cr3t-app-pw',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host']);

        // With the basic fields present (still no explicit auth_mode) it succeeds
        // and the row records auth_mode=basic from the merged default.
        $response = $this->actingAs($admin)
            ->postJson('/api/admin/connectors/imap/configure', [
                'host' => 'imap.example.com',
                'username' => 'alice@example.com',
                'password' => 's3cr3t-app-pw',
            ])
            ->assertOk();

        $this->assertSame('active', $response->json('data.status'));
        $installation = ConnectorInstallation::query()
            ->where('tenant_id', 'default')->where('connector_name', 'imap')->firstOrFail();
        $this->assertSame('basic', $installation->config_json['auth_mode']);
    }

    public function test_explicit_null_auth_mode_is_treated_as_omitted_and_still_requires_basic_fields(): void
    {
        $this->bindImapFactory(pingSucceeds: true);

        // A JSON client sends auth_mode: null explicitly. It must be treated like
        // "omitted" → the 'basic' default is merged → host/password are required.
        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/configure', [
                'auth_mode' => null,
                'username' => 'alice@example.com',
                'password' => 's3cr3t-app-pw',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host']);
    }

    public function test_xoauth2_configure_returns_provider_redirect_and_stays_pending(): void
    {
        config([
            'connectors.providers.imap.xoauth2.google.client_id' => 'test-client-id',
            'connectors.providers.imap.xoauth2.google.redirect_uri' => 'https://host.test/api/admin/connectors/imap/oauth/callback',
        ]);
        $this->bindImapFactory(pingSucceeds: true);

        $response = $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/configure', [
                'auth_mode' => 'xoauth2',
                'xoauth2_provider' => 'google',
                'username' => 'alice@gmail.com',
            ])
            ->assertOk();

        $this->assertSame('pending', $response->json('data.status'));
        $redirect = (string) $response->json('data.redirect_to');
        $this->assertStringContainsString('accounts.google.com', $redirect);
        $this->assertStringContainsString('state=', $redirect);
        $this->assertStringContainsString('scope=', $redirect);
        $this->assertStringContainsString('client_id=test-client-id', $redirect);

        $installation = ConnectorInstallation::query()
            ->where('tenant_id', 'default')->where('connector_name', 'imap')->firstOrFail();
        $this->assertSame(ConnectorInstallation::STATUS_PENDING, $installation->status);
        $this->assertSame('xoauth2', $installation->config_json['auth_mode']);
        $this->assertSame('google', $installation->config_json['xoauth2_provider']);
        // showIf: basic-only fields (host/port/encryption/validate_cert) must NOT
        // be persisted in xoauth2 mode — only the always-shown username.
        $connection = $installation->config_json['connection'] ?? [];
        $this->assertSame(['username' => 'alice@gmail.com'], $connection);
    }

    public function test_configure_is_scoped_to_the_active_tenant(): void
    {
        // A pre-existing installation owned by a DIFFERENT tenant must be left
        // untouched — configure upserts only the active tenant's (default) row.
        $foreign = ConnectorInstallation::create([
            'tenant_id' => 'tenant-foreign',
            'connector_name' => 'imap',
            'config_json' => ['auth_mode' => 'basic', 'connection' => ['host' => 'foreign.example.com']],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);

        $this->bindImapFactory(pingSucceeds: true);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/configure', $this->basicPayload())
            ->assertOk();

        $foreign->refresh();
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $foreign->status);
        $this->assertSame('foreign.example.com', $foreign->config_json['connection']['host']);

        // The active tenant got its OWN separate row.
        $this->assertSame(
            2,
            ConnectorInstallation::query()->where('connector_name', 'imap')->count(),
            'configure must create a distinct row for the active tenant, not mutate the foreign one.',
        );
    }

    public function test_configure_creates_multiple_accounts_with_distinct_labels(): void
    {
        $this->bindImapFactory(pingSucceeds: true);
        $admin = $this->superAdmin();

        foreach (['Support', 'Sales'] as $label) {
            $this->actingAs($admin)
                ->postJson('/api/admin/connectors/imap/configure', $this->basicPayload(['label' => $label]))
                ->assertOk();
        }

        $this->assertSame(
            2,
            ConnectorInstallation::query()
                ->where('tenant_id', 'default')->where('connector_name', 'imap')->count(),
        );
        $labels = ConnectorInstallation::query()
            ->where('connector_name', 'imap')->orderBy('label')->pluck('label')->all();
        $this->assertSame(['Sales', 'Support'], $labels);
    }

    public function test_configure_rejects_a_duplicate_label_for_the_same_connector(): void
    {
        // E2E §7 invariant: a third account reusing an existing label is rejected
        // by the (tenant, connector, label) unique — a 422, never a silent merge.
        $this->bindImapFactory(pingSucceeds: true);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/connectors/imap/configure', $this->basicPayload(['label' => 'Support']))
            ->assertOk();

        $this->actingAs($admin)
            ->postJson('/api/admin/connectors/imap/configure', $this->basicPayload(['label' => 'Support']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label']);

        $this->assertSame(
            1,
            ConnectorInstallation::query()->where('connector_name', 'imap')->count(),
        );
    }

    public function test_configure_without_project_key_inherits_the_tenant_default(): void
    {
        // R43 — the OTHER state: no project binding → project_key column stays
        // null (BaseConnector::resolveProjectKey falls back to the tenant default).
        $this->bindImapFactory(pingSucceeds: true);

        $payload = $this->basicPayload();
        unset($payload['project_key']);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/configure', $payload)
            ->assertOk();

        $installation = ConnectorInstallation::query()
            ->where('tenant_id', 'default')->where('connector_name', 'imap')->firstOrFail();
        $this->assertNull($installation->project_key);
    }

    // ── Pre-save connection test (the "Test connection" button) ────────────────

    public function test_test_connection_returns_ok_when_the_ping_succeeds_and_persists_nothing(): void
    {
        $this->bindImapFactory(pingSucceeds: true);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/test-connection', $this->basicPayload())
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        // The whole point of the pre-save test: it verifies WITHOUT saving. No
        // installation row, no vaulted secret — Connect is what persists.
        $this->assertSame(
            0,
            ConnectorInstallation::query()->where('connector_name', 'imap')->count(),
            'A connection test must never create an installation row.',
        );
    }

    public function test_test_connection_returns_not_ok_when_the_ping_fails_and_persists_nothing(): void
    {
        $this->bindImapFactory(pingSucceeds: false);

        $response = $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/test-connection', $this->basicPayload())
            ->assertOk();

        // R14 — a failed test is an explicit negative result the FE reads, with a
        // reason; NOT a silent success and NOT a persisted row.
        $this->assertFalse($response->json('ok'));
        $this->assertNotEmpty($response->json('error'));
        $this->assertSame(0, ConnectorInstallation::query()->where('connector_name', 'imap')->count());
    }

    public function test_test_connection_reports_missing_fields_without_calling_the_server(): void
    {
        // No factory bound on purpose: with an empty payload the service must
        // short-circuit on the missing host/password BEFORE it ever builds a
        // client, so this proves the guard (and that it never 500s).
        $response = $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/test-connection', [])
            ->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertNotEmpty($response->json('error'));
    }

    public function test_test_connection_missing_username_hits_the_friendly_guard(): void
    {
        // The guard message promises host + username + password: a missing username
        // must reach THIS friendly error, not fall through to a factory-level
        // "Could not connect: …". No factory bound — the guard short-circuits first.
        $payload = $this->basicPayload();
        unset($payload['username']);

        $response = $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/test-connection', $payload)
            ->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('username', strtolower((string) $response->json('error')));
    }

    public function test_test_connection_rejects_xoauth2_with_a_clear_message(): void
    {
        // xoauth2 has no synchronous pre-save ping — the endpoint says so plainly
        // instead of pretending to test (R43 — the OTHER auth mode is handled).
        $response = $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/test-connection', [
                'auth_mode' => 'xoauth2',
                'xoauth2_provider' => 'google',
                'username' => 'alice@gmail.com',
            ])
            ->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('password', strtolower((string) $response->json('error')));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function basicPayload(array $overrides = []): array
    {
        return array_merge([
            'auth_mode' => 'basic',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => 'alice@example.com',
            'password' => 's3cr3t-app-pw',
            'project_key' => 'support-mailbox',
        ], $overrides);
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
                throw new \LogicException('not used in configure');
            }

            public function selectMailbox(string $name): MailboxState
            {
                throw new \LogicException('not used in configure');
            }

            public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
            {
                throw new \LogicException('not used in configure');
            }

            public function fetchMessage(string $mailbox, int $uid): ImapMessage
            {
                throw new \LogicException('not used in configure');
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
        // The registry instantiates every connector in its constructor, so drop
        // the cached singleton to force a rebuild that picks up the fake factory.
        $this->app->forgetInstance(ConnectorRegistry::class);
    }
}
