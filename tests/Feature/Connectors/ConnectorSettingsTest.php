<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * v8.25 — the schema-driven connector settings surface: the index/resource embeds
 * the connector's full editable `connection_settings_schema` + current `settings`
 * values, the PATCH writes the whole surface into config_json (validated
 * dynamically against the schema), and folder discovery 404s for a connector that
 * doesn't implement {@see \Padosoft\AskMyDocsConnectorBase\Contracts\SupportsFolderDiscovery}.
 */
final class ConnectorSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        // Spatie's permission cache can survive RefreshDatabase rollbacks under
        // Testbench — flush it so role checks stay deterministic in suite order.
        Cache::flush();
        app(TenantContext::class)->set('default');
    }

    public function test_index_exposes_the_full_settings_schema_and_current_values(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->makeImapInstallation(['folders' => ['exclude' => ['Trash']], 'date_window_days' => 120]);

        $resp = $this->actingAs($admin)->getJson('/api/admin/connectors');
        $resp->assertOk();

        $imap = collect($resp->json('data'))->firstWhere('key', 'imap');
        $this->assertNotNull($imap, 'imap connector must be listed');
        $installation = $imap['installations'][0];

        $names = array_map(static fn ($f) => $f['name'], $installation['connection_settings_schema']);
        foreach (['folders.include', 'folders.exclude', 'date_window_days', 'senders.exclude'] as $expected) {
            $this->assertContains($expected, $names, "schema must expose '{$expected}'");
        }

        // Current values are a nested partial of config_json.
        $this->assertSame(120, data_get($installation, 'settings.date_window_days'));
        $this->assertSame(['Trash'], data_get($installation, 'settings.folders.exclude'));
    }

    public function test_patch_settings_writes_the_full_surface_to_config_json(): void
    {
        $admin = $this->makeSuperAdmin();
        $inst = $this->makeImapInstallation();

        $this->actingAs($admin)->patchJson("/api/admin/connectors/{$inst->id}", [
            'settings' => [
                'folders' => ['include' => ['INBOX'], 'exclude' => ['Trash', 'Spam']],
                'date_window_days' => 90,
                'senders' => ['exclude' => ['noreply@x.com']],
                'skip_auto_generated' => false,
            ],
        ])->assertOk();

        $inst->refresh();
        $this->assertSame(['INBOX'], data_get($inst->config_json, 'folders.include'));
        $this->assertSame(['Trash', 'Spam'], data_get($inst->config_json, 'folders.exclude'));
        $this->assertSame(90, data_get($inst->config_json, 'date_window_days'));
        $this->assertSame(['noreply@x.com'], data_get($inst->config_json, 'senders.exclude'));
        $this->assertFalse(data_get($inst->config_json, 'skip_auto_generated'));
        // Connection config is preserved — settings never touch it.
        $this->assertSame('imap.example.test', data_get($inst->config_json, 'connection.host'));
    }

    public function test_patch_settings_rejects_a_bad_type_with_422(): void
    {
        $admin = $this->makeSuperAdmin();
        $inst = $this->makeImapInstallation();

        $this->actingAs($admin)->patchJson("/api/admin/connectors/{$inst->id}", [
            'settings' => ['date_window_days' => 'not-a-number'],
        ])->assertStatus(422);
    }

    public function test_patch_settings_rejects_an_unknown_key_with_422(): void
    {
        $admin = $this->makeSuperAdmin();
        $inst = $this->makeImapInstallation();

        // A typo'd setting must 422, never 200-OK-then-silently-do-nothing (R14).
        $this->actingAs($admin)->patchJson("/api/admin/connectors/{$inst->id}", [
            'settings' => ['date_window_day' => 90],
        ])->assertStatus(422)->assertJsonValidationErrors(['settings.date_window_day']);
    }

    public function test_folder_discovery_404_for_a_non_discovering_connector(): void
    {
        $admin = $this->makeSuperAdmin();
        $gdrive = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'label' => 'default',
            'config_json' => [],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);

        // google-drive does not implement SupportsFolderDiscovery → 404 (R23 instanceof).
        $this->actingAs($admin)
            ->getJson("/api/admin/connectors/{$gdrive->id}/folders")
            ->assertStatus(404);
    }

    /**
     * @param  array<string,mixed>  $extraConfig
     */
    private function makeImapInstallation(array $extraConfig = []): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'mbox-'.uniqid(),
            'config_json' => array_merge([
                'auth_mode' => 'basic',
                'connection' => ['host' => 'imap.example.test', 'port' => 993, 'username' => 'u@example.test'],
            ], $extraConfig),
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'Super',
            'email' => 'super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }
}
