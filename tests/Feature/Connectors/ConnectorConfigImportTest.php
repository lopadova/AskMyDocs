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
 * v8.29 — the "Import parameters" surface. Proves import/validate returns a
 * SECRET-FREE prefill (secrets + unknown keys dropped, required secret fields
 * reported), rejects a bad envelope / connector mismatch, round-trips a real
 * export, and — via the CLI — creates an account with the operator-supplied
 * secret. RBAC (R32) covered. The IMAP server is the ONLY faked boundary (R13).
 */
final class ConnectorConfigImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        Project::create(['project_key' => 'support-mailbox', 'name' => 'Support Mailbox']);
        $this->bindImapFactory(pingSucceeds: true);
    }

    public function test_import_validate_returns_a_secret_free_prefill(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/import/validate', $this->validBlob())
            ->assertOk();

        $data = $response->json('data');

        $this->assertSame('imap', $data['connector']);
        $this->assertSame('Date', $data['label']);
        $this->assertSame('imap.example.com', $data['params']['host']);
        $this->assertSame('alice@example.com', $data['params']['username']);
        $this->assertSame('basic', $data['params']['auth_mode']);
        // The importer tells the FE which secret to require, but never a value.
        $this->assertSame(['password'], $data['secret_fields_required']);
        $this->assertArrayNotHasKey('password', $data['params']);
    }

    public function test_import_validate_strips_secret_and_unknown_keys(): void
    {
        $blob = $this->validBlob();
        $blob['params']['password'] = 'leaked-secret';       // a secret must never round-trip
        $blob['params']['totally_bogus'] = 'inject-me';       // an unknown key must be dropped

        $response = $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/import/validate', $blob)
            ->assertOk();

        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data['params']);
        $this->assertArrayNotHasKey('totally_bogus', $data['params']);
        $this->assertStringNotContainsString('leaked-secret', $response->getContent());
        $this->assertContains('password', $data['dropped_keys']);
        $this->assertContains('totally_bogus', $data['dropped_keys']);
    }

    public function test_import_validate_rejects_an_unrecognized_file(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/import/validate', ['params' => ['host' => 'x']])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_import_validate_rejects_a_connector_mismatch(): void
    {
        $blob = $this->validBlob();
        $blob['connector'] = 'google-drive';
        $blob['_meta']['connector'] = 'google-drive';

        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/import/validate', $blob)
            ->assertStatus(422);
    }

    public function test_import_validate_rejects_a_malformed_meta_without_500(): void
    {
        // A hand-edited / corrupted file whose `_meta` is not an array must degrade
        // to a clean 422, never a TypeError/500 from subscripting a non-array.
        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/connectors/imap/import/validate', [
                '_meta' => 'not-an-array',
                'connector' => 'imap',
                'params' => ['host' => 'x'],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_import_validate_forbidden_for_a_viewer(): void
    {
        $viewer = User::create([
            'name' => 'Vic', 'email' => 'viewer-'.uniqid().'@demo.local', 'password' => bcrypt('secret-password'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)
            ->postJson('/api/admin/connectors/imap/import/validate', $this->validBlob())
            ->assertForbidden();
    }

    public function test_export_then_import_round_trips(): void
    {
        // Create a real account, export it, then feed the exported blob straight
        // back into import/validate — the prefill must reproduce the params.
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'Roundtrip',
            'project_key' => 'support-mailbox',
            'config_json' => [
                'auth_mode' => 'basic',
                'connection' => ['host' => 'imap.rt.tld', 'port' => 993, 'encryption' => 'ssl', 'validate_cert' => true, 'username' => 'rt@example.com'],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);

        $admin = $this->superAdmin();
        $exported = $this->actingAs($admin)
            ->getJson("/api/admin/connectors/{$installation->id}/export")
            ->assertOk()
            ->json('data');

        $prefill = $this->actingAs($admin)
            ->postJson('/api/admin/connectors/imap/import/validate', $exported)
            ->assertOk()
            ->json('data');

        $this->assertSame('imap.rt.tld', $prefill['params']['host']);
        $this->assertSame('rt@example.com', $prefill['params']['username']);
        $this->assertSame(['password'], $prefill['secret_fields_required']);
    }

    public function test_import_cli_creates_an_account_from_a_file(): void
    {
        // Seed an actor for created_by (the CLI picks the first user).
        $this->superAdmin();

        $path = tempnam(sys_get_temp_dir(), 'conn-import-').'.json';
        file_put_contents($path, json_encode($this->validBlob(['label' => 'CliImported'])));

        try {
            $this->artisan('connectors:import', ['file' => $path, '--tenant' => 'default'])
                ->expectsQuestion("Enter 'password' (hidden)", 'typed-in-pw')
                ->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $installation = ConnectorInstallation::query()
            ->where('tenant_id', 'default')->where('connector_name', 'imap')->where('label', 'CliImported')->first();
        $this->assertNotNull($installation);
        $this->assertSame('imap.example.com', $installation->config_json['connection']['host']);
        // The operator-entered secret was vaulted (never from the file).
        $this->assertSame('typed-in-pw', app(OAuthCredentialVault::class)->getAccessToken($installation->id));
    }

    public function test_import_cli_fails_on_a_missing_file(): void
    {
        $this->artisan('connectors:import', ['file' => '/no/such/file.json', '--tenant' => 'default'])
            ->assertFailed();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function validBlob(array $overrides = []): array
    {
        return array_merge([
            '_meta' => ['format' => 'askmydocs.connector-config', 'version' => 1, 'connector' => 'imap'],
            'connector' => 'imap',
            'label' => 'Date',
            'project_key' => 'support-mailbox',
            'params' => [
                'auth_mode' => 'basic',
                'host' => 'imap.example.com',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => true,
                'username' => 'alice@example.com',
            ],
            'settings' => [],
            'secret_fields_omitted' => ['password'],
        ], $overrides);
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
                throw new \LogicException('not used in import');
            }

            public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
            {
                throw new \LogicException('not used in import');
            }

            public function fetchMessage(string $mailbox, int $uid): ImapMessage
            {
                throw new \LogicException('not used in import');
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
}
