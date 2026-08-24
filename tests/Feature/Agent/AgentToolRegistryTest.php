<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\Tools\AgentToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
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
