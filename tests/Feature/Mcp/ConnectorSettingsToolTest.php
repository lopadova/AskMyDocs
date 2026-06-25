<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\ConnectorSettingsTool;
use App\Services\Admin\Connectors\ConnectorFolderListingService;
use App\Services\Admin\Connectors\ConnectorInstallationService;
use App\Services\Admin\Connectors\ConnectorSettingsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * v8.25 — the connector settings MCP read surface (R44 third surface).
 * Tenant-scoped (R30); delegates to the SAME core (ConnectorSettingsService) as
 * the HTTP resource + `connectors:configure`. A cross-tenant / unknown id is a
 * clean "not found", never another tenant's config (R30/R14).
 */
final class ConnectorSettingsToolTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenants;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep cached state deterministic across the suite under Testbench.
        Cache::flush();
        $this->tenants = app(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->tenants->reset();
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    private function invoke(array $args): array
    {
        $response = (new ConnectorSettingsTool())->handle(
            new Request($args),
            app(ConnectorInstallationService::class),
            app(ConnectorSettingsService::class),
            app(ConnectorFolderListingService::class),
            $this->tenants,
        );

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_returns_the_schema_and_current_values_for_a_tenant_account(): void
    {
        $inst = ConnectorInstallation::create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'imap',
            'label' => 'mbox',
            'config_json' => ['date_window_days' => 200, 'folders' => ['exclude' => ['Trash']]],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->tenants->set('tenant-a');
        $payload = $this->invoke(['installation_id' => $inst->id]);

        $this->assertSame('tenant-a', $payload['tenant_id']);
        $this->assertSame('imap', $payload['installation']['connector_name']);
        $names = array_map(static fn ($f) => $f['name'], $payload['connection_settings_schema']);
        $this->assertContains('folders.include', $names);
        $this->assertSame(200, data_get($payload, 'settings.date_window_days'));
        $this->assertSame(['Trash'], data_get($payload, 'settings.folders.exclude'));
        // Folder discovery is opt-in — absent unless requested.
        $this->assertNull($payload['folders']);
    }

    public function test_cross_tenant_installation_is_a_clean_not_found(): void
    {
        $foreign = ConnectorInstallation::create([
            'tenant_id' => 'tenant-b',
            'connector_name' => 'imap',
            'label' => 'mbox',
            'config_json' => [],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->tenants->set('tenant-a');
        $payload = $this->invoke(['installation_id' => $foreign->id]);

        $this->assertArrayHasKey('error', $payload);
        $this->assertArrayNotHasKey('settings', $payload);
    }

    public function test_include_folders_is_null_for_a_non_discovering_connector(): void
    {
        $inst = ConnectorInstallation::create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'google-drive',
            'label' => 'default',
            'config_json' => [],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->tenants->set('tenant-a');
        // include_folders requested, but google-drive has no folder discovery →
        // folders stays null with no folders_error (R43 clean degrade, not a 503).
        $payload = $this->invoke(['installation_id' => $inst->id, 'include_folders' => true]);

        $this->assertNull($payload['folders']);
        $this->assertNull($payload['folders_error']);
    }
}
