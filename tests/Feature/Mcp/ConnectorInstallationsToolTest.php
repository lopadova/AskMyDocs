<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\ConnectorInstallationsTool;
use App\Services\Admin\Connectors\ConnectorInstallationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * v8.20 — the multi-account connectors MCP read surface (R44 third surface).
 * Tenant-scoped (R30); multi-account aware; OFF-safe (R43 — no installations is
 * a well-formed empty roster, not an error). Delegates to the SAME core as the
 * HTTP index + `connectors:list` command.
 */
final class ConnectorInstallationsToolTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenants = app(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->tenants->reset();
        parent::tearDown();
    }

    private function invoke(array $args): array
    {
        $tool = new ConnectorInstallationsTool();
        $response = $tool->handle(
            new Request($args),
            app(ConnectorInstallationService::class),
            $this->tenants,
        );

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_lists_accounts_for_the_active_tenant_only(): void
    {
        foreach (['support', 'sales'] as $label) {
            ConnectorInstallation::create([
                'tenant_id' => 'tenant-a',
                'connector_name' => 'google-drive',
                'label' => $label,
                'project_key' => $label === 'support' ? null : 'acme',
                'status' => ConnectorInstallation::STATUS_ACTIVE,
            ]);
        }
        // A foreign tenant's account must never surface.
        ConnectorInstallation::create([
            'tenant_id' => 'tenant-b',
            'connector_name' => 'google-drive',
            'label' => 'support',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->tenants->set('tenant-a');
        $payload = $this->invoke([]);

        $this->assertSame('tenant-a', $payload['tenant_id']);
        $this->assertSame(2, $payload['total_installations']);
        $this->assertCount(1, $payload['connectors']);
        $entry = $payload['connectors'][0];
        $this->assertSame('google-drive', $entry['key']);
        $labels = collect($entry['installations'])->pluck('label')->all();
        $this->assertSame(['sales', 'support'], $labels);
    }

    public function test_empty_roster_is_well_formed_when_nothing_is_installed(): void
    {
        // R43 — the OFF/empty state: no accounts → empty connectors list + zero
        // total, never an error.
        $this->tenants->set('tenant-a');
        $payload = $this->invoke([]);

        $this->assertSame(0, $payload['total_installations']);
        $this->assertSame([], $payload['connectors']);
    }

    public function test_connector_filter_restricts_the_roster(): void
    {
        ConnectorInstallation::create([
            'tenant_id' => 'tenant-a', 'connector_name' => 'google-drive',
            'label' => 'a', 'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);
        ConnectorInstallation::create([
            'tenant_id' => 'tenant-a', 'connector_name' => 'notion',
            'label' => 'b', 'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->tenants->set('tenant-a');
        $payload = $this->invoke(['connector' => 'notion']);

        $this->assertCount(1, $payload['connectors']);
        $this->assertSame('notion', $payload['connectors'][0]['key']);
    }
}
