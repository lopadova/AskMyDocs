<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Http\Controllers\Api\AgentLiveSourceController;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Tests\TestCase;

final class AgentLiveSourceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        Route::middleware('api')->get('/test-chat/live-sources', AgentLiveSourceController::class);
    }

    public function test_it_returns_only_authorized_read_only_sources_grouped_by_connection(): void
    {
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.runtime_mode', 'active');
        app(TenantContext::class)->set('acme');
        app(ConnectorTenantContext::class)->set('acme');
        $user = $this->user('live-sources@example.test');
        ProjectMembership::query()->create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'crm',
            'role' => 'member',
        ]);
        $this->apiRoute('orders_list');
        $this->apiRoute('orders_get');
        $connection = $this->mcpConnection();

        $response = $this->actingAs($user)->getJson('/test-chat/live-sources?project_key=crm');

        $response->assertOk()
            ->assertJsonPath('data.api.0.name', 'Commerce')
            ->assertJsonPath('data.api.0.tool_count', 2)
            ->assertJsonPath('data.mcp.0.key', 'mcp:'.$connection->public_id)
            ->assertJsonPath('data.mcp.0.name', 'HubHive')
            ->assertJsonPath('data.mcp.0.tool_count', 1);
    }

    public function test_it_rejects_catalog_access_for_an_unreachable_project(): void
    {
        app(TenantContext::class)->set('acme');
        $user = $this->user('no-project@example.test');

        $this->actingAs($user)
            ->getJson('/test-chat/live-sources?project_key=private')
            ->assertForbidden();
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Live source user',
            'email' => $email,
            'password' => Hash::make('secret-pass-123'),
        ]);
    }

    private function apiRoute(string $slug): ApiRoute
    {
        $connector = ApiConnector::query()->firstOrCreate(
            ['tenant_id' => 'acme', 'project_key' => 'crm', 'name' => 'Commerce'],
            ['is_active' => true],
        );

        return ApiRoute::query()->create([
            'tenant_id' => 'acme',
            'api_connector_id' => $connector->id,
            'project_key' => 'crm',
            'name' => $slug,
            'slug' => $slug,
            'description' => $slug,
            'http_method' => 'GET',
            'url' => 'https://commerce.example.test/'.$slug,
            'mode' => 'tool',
            'status' => 'active',
            'endpoint_type' => str_ends_with($slug, 'list') ? 'list' : 'detail',
            'tool_definition' => [
                'name' => $slug,
                'description' => $slug,
                'input_schema' => ['type' => 'object', 'properties' => []],
            ],
        ]);
    }

    private function mcpConnection(): McpConnection
    {
        $server = McpServerDefinition::query()->create([
            'tenant_id' => 'acme',
            'name' => 'HubHive',
            'catalog_scope' => 'tenant',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://hubhive.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $connection = McpConnection::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'HubHive',
            'project_key' => 'crm',
            'status' => McpConnection::STATUS_ACTIVE,
        ]);
        McpConnectionTool::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => 'orders.list',
            'local_name' => 'mcp_hubhive_orders_list_12345678',
            'description' => 'List orders.',
            'input_schema_json' => ['type' => 'object', 'properties' => []],
            'annotations_json' => ['readOnlyHint' => true],
            'risk' => 'read',
            'policy' => 'enabled',
            'enabled' => true,
            'confirmation_required' => false,
        ]);

        return $connection;
    }
}
