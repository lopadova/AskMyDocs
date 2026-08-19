<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
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
