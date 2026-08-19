<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\McpConnector;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionResource;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolExecutor;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Tests\TestCase;
use Tests\Support\Mcp\StubMcpTransport;

final class McpConnectorHostRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.routes.admin_ability', 'manageConnectors');
        app(TenantContext::class)->set('test-tenant');
        app(ConnectorTenantContext::class)->set('test-tenant');
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_admin_lists_only_shared_connections_for_the_active_tenant(): void
    {
        $admin = $this->user('admin@example.test');
        $admin->assignRole('admin');
        $server = $this->server('test-tenant', 'Shared MCP');
        $shared = $this->connection($server, 'shared');
        $this->connection($server, 'personal', $admin);
        $other = $this->server('other-tenant', 'Other MCP');
        $this->connection($other, 'shared');

        $response = $this->actingAs($admin)->getJson('/api/admin/connectors/mcp');

        $response->assertOk()->assertJsonCount(1);
        $response->assertJsonPath('0.public_id', $shared->public_id);
    }

    public function test_personal_connected_apps_are_owner_scoped(): void
    {
        $owner = $this->user('owner@example.test');
        $otherUser = $this->user('other@example.test');
        $server = $this->server('test-tenant', 'Personal MCP');
        $owned = $this->connection($server, 'personal', $owner);
        $this->connection($server, 'personal', $otherUser);
        $this->connection($server, 'shared');

        $response = $this->actingAs($owner)->getJson('/api/me/connected-apps/mcp');

        $response->assertOk()->assertJsonCount(1);
        $response->assertJsonPath('0.public_id', $owned->public_id);
        $response->assertJsonPath('0.owner_id', $owner->getKey());
    }

    public function test_feature_flag_hides_the_new_surface(): void
    {
        config()->set('connector-mcp.enabled', false);
        $user = $this->user('disabled@example.test');

        $this->actingAs($user)->getJson('/api/me/connected-apps/mcp')->assertNotFound();
    }

    public function test_mcp_resource_connector_is_registered_but_hidden_from_generic_roster(): void
    {
        $this->assertInstanceOf(McpConnector::class, app(ConnectorRegistry::class)->get(McpConnector::KEY));

        $admin = $this->user('roster@example.test');
        $admin->assignRole('admin');
        $keys = collect($this->actingAs($admin)->getJson('/api/admin/connectors')->assertOk()->json('data'))
            ->pluck('key');

        $this->assertNotContains(McpConnector::KEY, $keys);
    }

    public function test_admin_can_enable_and_queue_selected_resources(): void
    {
        Bus::fake();
        $admin = $this->user('resource-admin@example.test');
        $admin->assignRole('admin');
        $server = $this->server('test-tenant', 'Resource MCP');
        $installation = ConnectorInstallation::query()->create([
            'tenant_id' => 'test-tenant',
            'connector_name' => McpConnector::KEY,
            'label' => 'Resource MCP',
            'project_key' => 'default',
            'config_json' => [],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);
        $connection = $this->connection($server, 'shared');
        $connection->forceFill(['connector_installation_id' => $installation->getKey()])->save();
        $installation->forceFill(['config_json' => ['mcp_connection_public_id' => $connection->public_id]])->save();
        $resource = McpConnectionResource::query()->create([
            'tenant_id' => 'test-tenant',
            'mcp_connector_connection_id' => $connection->getKey(),
            'uri' => 'docs://handbook',
            'uri_hash' => hash('sha256', 'docs://handbook'),
            'name' => 'Handbook',
        ]);

        $this->actingAs($admin)->putJson(
            "/api/admin/connectors/mcp/{$connection->public_id}/resources/{$resource->getKey()}",
            ['enabled' => true],
        )->assertOk()->assertJsonPath('enabled', true);
        $this->actingAs($admin)->postJson(
            "/api/admin/connectors/mcp/{$connection->public_id}/resources/sync",
        )->assertAccepted()->assertJsonPath('status', 'queued');

        Bus::assertDispatched(ConnectorSyncJob::class, fn (ConnectorSyncJob $job): bool => $job->installationId === $installation->getKey());
    }

    public function test_confirmation_resume_executes_and_is_audited_through_the_host_listener(): void
    {
        $user = $this->user('confirm@example.test');
        $server = $this->server('test-tenant', 'Writable MCP');
        $connection = $this->connection($server, 'shared');
        $tool = McpConnectionTool::query()->create([
            'tenant_id' => 'test-tenant',
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => 'records.update',
            'local_name' => 'writable_records_update_12345678',
            'input_schema_json' => ['type' => 'object'],
            'risk' => 'write',
            'policy' => 'enabled',
            'enabled' => true,
            'confirmation_required' => true,
        ]);
        McpClient::useTransportResolver(static function (McpServerContract $server): McpTransportContract {
            $transport = new StubMcpTransport;
            $transport->responses['server/discover'] = [
                'protocolVersion' => '2026-07-28',
                'capabilities' => ['tools' => []],
            ];

            return $transport->scriptToolCall('records.update', [
                'content' => [['type' => 'text', 'text' => 'Write completed.']],
            ]);
        });

        $pending = app(McpToolExecutor::class)->invoke(
            $tool->local_name,
            ['value' => 'new'],
            $user,
            'conversation-1',
        );
        $this->assertSame('confirmation_required', $pending->status);

        $this->actingAs($user)->postJson(
            '/api/conversations/mcp/interactions/'.$pending->pendingInteractionId,
            ['conversation_id' => 'conversation-1', 'response' => ['confirmed' => true]],
        )->assertOk()->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('mcp_tool_call_audit', [
            'tenant_id' => 'test-tenant',
            'user_id' => $user->getKey(),
            'source' => 'mcp_connector',
            'mcp_connection_id' => $connection->public_id,
            'tool_remote_name' => 'records.update',
            'tool_local_name' => $tool->local_name,
            'status' => 'ok',
        ]);
    }

    public function test_remote_task_status_route_is_actor_and_conversation_scoped(): void
    {
        $user = $this->user('task-owner@example.test');
        $other = $this->user('task-other@example.test');
        $server = $this->server('test-tenant', 'Task MCP');
        $connection = $this->connection($server, 'shared');
        $tool = McpConnectionTool::query()->create([
            'tenant_id' => 'test-tenant',
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => 'reports.generate',
            'local_name' => 'task_reports_generate_12345678',
            'input_schema_json' => ['type' => 'object'],
            'risk' => 'read',
            'policy' => 'enabled',
            'enabled' => true,
            'confirmation_required' => false,
        ]);
        $transport = new StubMcpTransport;
        $transport->responses['server/discover'] = [
            'protocolVersion' => '2026-07-28',
            'capabilities' => ['extensions' => ['io.modelcontextprotocol/tasks' => []]],
        ];
        $transport->scriptToolCall('reports.generate', [
            'resultType' => 'task',
            'taskId' => 'remote-host-task',
            'status' => 'working',
            'ttlMs' => 60_000,
            'pollIntervalMs' => 250,
        ]);
        $transport->responses['tasks/get'] = [
            'resultType' => 'complete',
            'taskId' => 'remote-host-task',
            'status' => 'completed',
            'ttlMs' => 60_000,
            'result' => ['content' => [['type' => 'text', 'text' => 'Generated host report.']]],
        ];
        McpClient::useTransportResolver(static fn (): McpTransportContract => $transport);

        $outcome = app(McpToolExecutor::class)->invoke(
            $tool->local_name,
            [],
            $user,
            'conversation-task',
        );
        $this->assertSame('task_accepted', $outcome->status);
        DB::table('mcp_connector_remote_tasks')->update(['next_poll_at' => now()->subSecond()]);

        $endpoint = '/api/conversations/mcp/tasks/'.$outcome->taskId.'?conversation_id=conversation-task';
        $this->actingAs($other)->getJson($endpoint)->assertNotFound();
        $this->actingAs($user)->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('artifact.text', 'Generated host report.');
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => str($email)->before('@')->headline()->toString(),
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }

    private function server(string $tenantId, string $name): McpServerDefinition
    {
        return McpServerDefinition::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'catalog_scope' => 'tenant',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://'.str($name)->slug().'.example.test/mcp',
            'endpoint_hash' => hash('sha256', $name),
            'status' => 'active',
            'created_by' => 'test',
        ]);
    }

    private function connection(McpServerDefinition $server, string $mode, ?User $owner = null): McpConnection
    {
        return McpConnection::withoutGlobalScopes()->create([
            'tenant_id' => $server->tenant_id,
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => $mode,
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner === null ? null : (string) $owner->getKey(),
            'label' => $server->name.' '.$mode,
            'status' => 'active',
        ]);
    }
}
