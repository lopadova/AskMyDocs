<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Tools\AgentServerToolRunner;
use App\Agent\Tools\AgentToolDefinition;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolCatalogFingerprint;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Tests\Support\Mcp\StubMcpTransport;
use Tests\TestCase;

final class AgentServerToolRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.runtime_mode', 'active');
        app(TenantContext::class)->set('acme');
        app(ConnectorTenantContext::class)->set('acme');
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_connector_mcp_tool_is_executed_through_the_agent_runner(): void
    {
        $user = User::query()->create([
            'name' => 'Agent MCP runner',
            'email' => 'agent-mcp-runner@example.test',
            'password' => Hash::make('secret-pass-123'),
        ]);
        $conversation = Conversation::query()->create([
            'tenant_id' => 'acme',
            'user_id' => $user->getKey(),
            'title' => 'MCP agent runner',
            'project_key' => 'orders',
        ]);
        $run = AgentRun::query()->create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'acme',
            'project_key' => 'orders',
            'user_id' => $user->getKey(),
            'conversation_id' => $conversation->getKey(),
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => (string) $user->getKey(),
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING,
            'input_json' => ['question' => 'Quali sono i miei ordini?'],
            'counters_json' => [],
            'started_at' => now(),
        ]);
        $server = McpServerDefinition::query()->create([
            'tenant_id' => 'acme',
            'name' => 'Gescat',
            'catalog_scope' => 'tenant',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://gescat.example.test/mcp/clienti',
            'status' => McpServerDefinition::STATUS_ACTIVE,
            'negotiated_era' => 'modern',
            'negotiated_version' => McpClient::MODERN_PROTOCOL_VERSION,
            'capabilities_json' => ['tools' => []],
            'server_info_json' => ['name' => 'Gescat'],
            'last_discovered_at' => now(),
        ]);
        $connection = McpConnection::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Gescat',
            'project_key' => 'orders',
            'status' => McpConnection::STATUS_ACTIVE,
            'last_discovered_at' => now(),
        ]);
        $tool = McpConnectionTool::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => 'list-my-orders',
            'local_name' => 'mcp_gescat_list_my_orders_12345678',
            'description' => 'Elenca gli ordini del cliente autenticato.',
            'input_schema_json' => ['type' => 'object', 'properties' => []],
            'annotations_json' => ['readOnlyHint' => true, 'idempotentHint' => true],
            'risk' => 'read',
            'policy' => 'enabled',
            'enabled' => true,
            'confirmation_required' => false,
        ]);
        $connection->forceFill([
            'catalog_hash' => app(McpToolCatalogFingerprint::class)->forConnection($connection),
        ])->save();
        $this->assertSame(
            $connection->fresh()->catalog_hash,
            app(McpToolCatalogFingerprint::class)->forConnection($connection),
        );
        $transport = new StubMcpTransport;
        $transport->responses['server/discover'] = [
            'protocolVersion' => '2026-07-28',
            'capabilities' => ['tools' => []],
        ];
        $transport->scriptToolCall('list-my-orders', [
            'content' => [['type' => 'text', 'text' => 'Ordine 123 del 26 agosto.']],
            'structuredContent' => ['orders' => [['id' => 123]]],
        ]);
        McpClient::useTransportResolver(
            static fn (McpServerContract $server): McpTransportContract => $transport,
        );
        $definition = new AgentToolDefinition(
            name: $tool->local_name,
            displayName: $tool->remote_name,
            description: $tool->description,
            kind: 'mcp',
            inputSchema: $tool->input_schema_json,
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: 1,
            executorReference: $tool->local_name,
            metadata: ['mcp_runtime' => 'connector'],
        );

        $result = app(AgentServerToolRunner::class)->execute(
            $definition,
            [],
            $this->context($run),
            $run,
            new AgentBudgetTracker($run),
        );

        $this->assertTrue($result->successful());
        $this->assertTrue($result->complete);
        $this->assertSame(1, $result->physicalRequests);
        $this->assertTrue((bool) data_get($result->stats, 'mcp.negotiation_cache_hit'));
        $this->assertSame(1, data_get($result->stats, 'mcp.physical_request_count'));
        $this->assertSame('completed', $result->body['status']);
        $this->assertStringStartsWith(
            'Ordine 123 del 26 agosto.',
            (string) data_get($result->body, 'artifact.text'),
        );
        $this->assertSame(123, data_get($result->body, 'artifact.structuredContent.orders.0.id'));
        $this->assertSame(123, data_get($result->body, 'orders.0.id'));
        $this->assertSame('connector', data_get($definition->metadata, 'mcp_runtime'));
        $this->assertSame(1, data_get($run->fresh()->counters_json, 'physical_calls'));
        $this->assertDatabaseHas('mcp_tool_call_audit', [
            'tenant_id' => 'acme',
            'user_id' => $user->getKey(),
            'source' => 'mcp_connector',
            'mcp_connection_id' => $connection->public_id,
            'tool_remote_name' => 'list-my-orders',
            'tool_local_name' => $tool->local_name,
            'status' => 'ok',
        ]);

        $connection->forceFill(['last_discovered_at' => now()->subHour()])->save();
        $transport->responses['tools/call:list-my-orders'] = JsonRpcMessage::errorResponse(
            'ignored-by-stub',
            -32603,
            'Temporary upstream failure.',
        );
        $failure = app(AgentServerToolRunner::class)->execute(
            $definition,
            [],
            $this->context($run),
            $run,
            new AgentBudgetTracker($run),
        );

        $this->assertFalse($failure->successful());
        $this->assertSame(2, $failure->physicalRequests);
        $this->assertSame('mcp_remote_error', $failure->stopReason);
        $this->assertSame(2, data_get($failure->stats, 'mcp.physical_request_count'));
        $this->assertSame(3, data_get($run->fresh()->counters_json, 'physical_calls'));
    }

    private function context(AgentRun $run): AgentExecutionContext
    {
        return new AgentExecutionContext(
            runId: $run->run_id,
            tenantId: $run->tenant_id,
            projectKey: $run->project_key,
            channel: $run->channel,
            actorType: $run->actor_type,
            actorId: $run->actor_id,
            locale: $run->locale,
            timezone: $run->timezone,
        );
    }
}
