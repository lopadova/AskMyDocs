<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Tests\Support\Mcp\StubMcpTransport;
use Tests\TestCase;

final class McpConnectorSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('test-tenant');
        app(ConnectorTenantContext::class)->set('test-tenant');
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_smoke_reports_protocol_catalogs_and_redacted_read_only_result(): void
    {
        [$connection] = $this->connectionWithTool(readOnly: true);
        McpClient::useTransportResolver(static function (): McpTransportContract {
            $transport = new StubMcpTransport;
            $transport->responses['server/discover'] = [
                'protocolVersion' => '2026-07-28',
                'capabilities' => ['tools' => [], 'resources' => []],
            ];
            $transport->responses['tools/list'] = ['tools' => [[
                'name' => 'docs.search',
                'inputSchema' => ['type' => 'object'],
            ]]];
            $transport->responses['resources/list'] = ['resources' => [[
                'uri' => 'docs://handbook',
                'name' => 'Handbook',
            ]]];

            return $transport->scriptToolCall('docs.search', [
                'content' => [['type' => 'text', 'text' => 'sensitive fresh result']],
            ]);
        });

        $exitCode = Artisan::call('mcp-connectors:smoke', [
            '--connection' => $connection->public_id,
            '--tool' => 'docs.search',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('"protocol_version": "2026-07-28"', $output);
        $this->assertStringContainsString('"content_types"', $output);
        $this->assertStringNotContainsString('sensitive fresh result', $output);
    }

    public function test_smoke_refuses_write_tool_execution(): void
    {
        [$connection] = $this->connectionWithTool(readOnly: false);
        McpClient::useTransportResolver(static function (): McpTransportContract {
            $transport = new StubMcpTransport;
            $transport->responses['server/discover'] = [
                'protocolVersion' => '2026-07-28',
                'capabilities' => ['tools' => []],
            ];

            return $transport->scriptListTools([]);
        });

        $this->artisan('mcp-connectors:smoke', [
            '--connection' => $connection->public_id,
            '--tool' => 'docs.search',
            '--json' => true,
        ])->expectsOutputToContain('restricted to enabled read-only tools')
            ->assertFailed();
    }

    /** @return array{McpConnection,McpConnectionTool} */
    private function connectionWithTool(bool $readOnly): array
    {
        $server = McpServerDefinition::query()->create([
            'tenant_id' => 'test-tenant',
            'name' => 'Smoke MCP',
            'catalog_scope' => 'tenant',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://smoke.example.test/mcp',
            'status' => 'active',
        ]);
        $connection = McpConnection::query()->create([
            'tenant_id' => 'test-tenant',
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Smoke',
            'status' => 'active',
        ]);
        $tool = McpConnectionTool::query()->create([
            'tenant_id' => 'test-tenant',
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => 'docs.search',
            'local_name' => 'smoke_docs_search_12345678',
            'input_schema_json' => ['type' => 'object'],
            'risk' => $readOnly ? 'read' : 'write',
            'policy' => 'enabled',
            'enabled' => true,
            'read_only' => $readOnly,
            'confirmation_required' => ! $readOnly,
        ]);

        return [$connection, $tool];
    }
}
