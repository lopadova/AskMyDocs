<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Migration\McpShadowComparisonService;
use App\Models\McpConnectorShadowReport;
use App\Models\McpServer;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Tests\Support\Mcp\StubMcpTransport;
use Tests\TestCase;

final class McpShadowComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.runtime_mode', 'shadow');
        app(TenantContext::class)->set('test-tenant');
        app(ConnectorTenantContext::class)->set('test-tenant');
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_matching_live_catalog_creates_a_redacted_match_report(): void
    {
        $legacy = $this->legacyServer();
        $this->scriptCatalog([$this->searchTool()]);

        $report = app(McpShadowComparisonService::class)->compare($legacy);

        $this->assertSame(McpConnectorShadowReport::MATCH, $report->status);
        $this->assertSame([], $report->blockers_json ?? []);
        $this->assertSame(1, $report->summary_json['legacy_enabled_tools']);
        $this->assertSame(1, $report->summary_json['connector_tools']);
        $this->assertSame('modern', $report->summary_json['negotiated_era']);
        $this->assertSame(64, strlen((string) $report->legacy_catalog_hash));
        $this->assertStringNotContainsString('secret', json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_missing_enabled_tool_is_a_blocking_drift(): void
    {
        $legacy = $this->legacyServer();
        $this->scriptCatalog([]);

        $report = app(McpShadowComparisonService::class)->compare($legacy);

        $this->assertSame(McpConnectorShadowReport::DRIFT, $report->status);
        $this->assertSame('enabled_tool_missing', $report->blockers_json[0]['code']);
        $this->assertSame('docs.search', $report->blockers_json[0]['tool']);
    }

    public function test_admin_report_endpoint_is_tenant_scoped(): void
    {
        $admin = $this->user('admin@example.test');
        $admin->assignRole('admin');
        McpConnectorShadowReport::withoutGlobalScopes()->create([
            'tenant_id' => 'test-tenant',
            'status' => 'match',
            'summary_json' => ['blockers' => 0],
            'compared_at' => now(),
        ]);
        McpConnectorShadowReport::withoutGlobalScopes()->create([
            'tenant_id' => 'other-tenant',
            'status' => 'error',
            'summary_json' => ['blockers' => 1],
            'compared_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/connectors/mcp/shadow-reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenant_id', 'test-tenant');
    }

    private function scriptCatalog(array $tools): void
    {
        McpClient::useTransportResolver(static function () use ($tools): McpTransportContract {
            $transport = new StubMcpTransport;
            $transport->responses['server/discover'] = [
                'protocolVersion' => '2026-07-28',
                'capabilities' => ['tools' => []],
                'serverInfo' => ['name' => 'shadow-fixture', 'version' => '1.0.0'],
            ];

            return $transport->scriptListTools($tools);
        });
    }

    private function legacyServer(): McpServer
    {
        $user = $this->user('owner@example.test');

        return McpServer::query()->create([
            'tenant_id' => 'test-tenant',
            'name' => 'Legacy docs',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'https://mcp.example.test/rpc',
            'enabled_tools_json' => ['docs.search'],
            'status' => McpServer::STATUS_ACTIVE,
            'last_handshake_at' => now(),
            'handshake_response_json' => [
                'protocol_version' => '2025-11-25',
                'capabilities' => ['tools' => true],
                'tools' => [$this->searchTool()],
            ],
            'created_by' => $user->getKey(),
        ]);
    }

    private function searchTool(): array
    {
        return [
            'name' => 'docs.search',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string']],
            ],
            'annotations' => ['readOnlyHint' => true],
        ];
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => str($email)->before('@')->headline()->toString(),
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }
}
