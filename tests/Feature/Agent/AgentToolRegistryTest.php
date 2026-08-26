<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\Tools\AgentToolRegistry;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Tests\TestCase;

final class AgentToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_combines_knowledge_with_only_tenant_project_scoped_api_tools(): void
    {
        $this->route('acme', 'orders', 'get_orders', pagination: ['type' => 'page', 'page_param' => 'page']);
        $this->route('acme', 'billing', 'get_invoices');
        $this->route('other', 'orders', 'foreign_orders');

        $tools = app(AgentToolRegistry::class)->forContext($this->context('acme', 'orders'));

        $this->assertSame(['search_knowledge_base', 'get_orders'], array_keys($tools));
        $this->assertSame('knowledge', $tools['search_knowledge_base']->kind);
        $this->assertTrue($tools['get_orders']->readOnly);
        $this->assertSame(100, $tools['get_orders']->physicalMaximum);
        $this->assertSame('page', data_get($tools['get_orders']->metadata, 'pagination.type'));
    }

    public function test_connector_tools_can_be_disabled_without_removing_knowledge_search(): void
    {
        config()->set('connector-api.chat_tools.enabled', false);
        $this->route('acme', 'orders', 'get_orders');

        $tools = app(AgentToolRegistry::class)->forContext($this->context('acme', 'orders'));

        $this->assertSame(['search_knowledge_base'], array_keys($tools));
    }

    public function test_active_mcp_connector_tools_are_exposed_to_the_agent_with_runtime_metadata(): void
    {
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.runtime_mode', 'active');
        app(TenantContext::class)->set('acme');
        app(ConnectorTenantContext::class)->set('acme');
        $user = User::query()->create([
            'name' => 'Agent MCP user',
            'email' => 'agent-mcp-registry@example.test',
            'password' => Hash::make('secret-pass-123'),
        ]);
        $tool = $this->mcpTool('acme', 'orders', 'list_my_orders_12345678');
        $this->mcpTool('acme', 'billing', 'list_my_invoices_12345678');
        $this->mcpTool('other', 'orders', 'foreign_mcp_tool_12345678');

        $tools = app(AgentToolRegistry::class)->forContext(
            $this->context('acme', 'orders'),
            $user,
        );

        $this->assertSame(['search_knowledge_base', $tool->local_name], array_keys($tools));
        $definition = $tools[$tool->local_name];
        $this->assertSame('mcp', $definition->kind);
        $this->assertSame('list-my-orders', $definition->displayName);
        $this->assertTrue($definition->readOnly);
        $this->assertTrue($definition->idempotent);
        $this->assertSame('connector', $definition->metadata['mcp_runtime']);
        $this->assertSame($tool->connection->public_id, data_get($definition->metadata, 'provenance.connection_id'));
    }

    public function test_off_mcp_connector_runtime_does_not_leak_new_catalog_tools(): void
    {
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.runtime_mode', 'off');
        config()->set('mcp.enabled', false);
        app(TenantContext::class)->set('acme');
        app(ConnectorTenantContext::class)->set('acme');
        $user = User::query()->create([
            'name' => 'Agent MCP off user',
            'email' => 'agent-mcp-off@example.test',
            'password' => Hash::make('secret-pass-123'),
        ]);
        $this->mcpTool('acme', 'orders', 'hidden_mcp_tool_12345678');

        $tools = app(AgentToolRegistry::class)->forContext(
            $this->context('acme', 'orders'),
            $user,
        );

        $this->assertSame(['search_knowledge_base'], array_keys($tools));
    }

    private function route(string $tenant, string $project, string $slug, ?array $pagination = null): ApiRoute
    {
        $connector = ApiConnector::create([
            'tenant_id' => $tenant,
            'project_key' => $project,
            'name' => $slug,
            'is_active' => true,
        ]);

        return ApiRoute::create([
            'tenant_id' => $tenant,
            'api_connector_id' => $connector->id,
            'project_key' => $project,
            'name' => $slug,
            'slug' => $slug,
            'description' => $slug,
            'http_method' => 'GET',
            'url' => 'https://api.example.test/'.$slug,
            'mode' => 'tool',
            'status' => 'active',
            'endpoint_type' => 'list',
            'pagination' => $pagination,
            'tool_definition' => [
                'name' => $slug,
                'description' => $slug,
                'input_schema' => ['type' => 'object', 'properties' => []],
            ],
        ]);
    }

    private function mcpTool(string $tenant, string $project, string $localName): McpConnectionTool
    {
        app(ConnectorTenantContext::class)->set($tenant);
        $server = McpServerDefinition::query()->create([
            'tenant_id' => $tenant,
            'name' => $localName,
            'catalog_scope' => 'tenant',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://'.$localName.'.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $connection = McpConnection::query()->create([
            'tenant_id' => $tenant,
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => $localName,
            'project_key' => $project,
            'status' => McpConnection::STATUS_ACTIVE,
        ]);
        $tool = McpConnectionTool::query()->create([
            'tenant_id' => $tenant,
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => str($localName)->beforeLast('_12345678')->replace('_', '-')->toString(),
            'local_name' => $localName,
            'description' => 'Read MCP data.',
            'input_schema_json' => ['type' => 'object', 'properties' => []],
            'annotations_json' => ['readOnlyHint' => true, 'idempotentHint' => true],
            'risk' => 'read',
            'policy' => 'enabled',
            'enabled' => true,
            'confirmation_required' => false,
        ]);
        app(ConnectorTenantContext::class)->set('acme');

        return $tool;
    }

    private function context(string $tenant, string $project): AgentExecutionContext
    {
        return new AgentExecutionContext(
            runId: 'run-registry',
            tenantId: $tenant,
            projectKey: $project,
            channel: 'chat',
            actorType: 'user',
            actorId: '1',
            locale: 'it-IT',
            timezone: 'Europe/Rome',
        );
    }
}
