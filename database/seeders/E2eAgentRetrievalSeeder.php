<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\WidgetKey;
use Illuminate\Database\Seeder;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;

/**
 * Deterministic live-tool fixtures for the real chat/widget Playwright flow.
 * Run after DemoSeeder; production never exposes either this seeder or the
 * /testing/api-fixture endpoints it targets.
 */
final class E2eAgentRetrievalSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->where('email', 'admin@demo.local')->update(['locale' => 'it-IT']);

        $this->seedScope(DemoSeeder::PRIMARY_TENANT, 'hr-portal', 'Chat E2E ERP');
        $this->seedScope('default', 'docs-v3', 'Widget E2E ERP');

        WidgetKey::query()->updateOrCreate(
            ['public_key' => 'pk_demo_local'],
            [
                'tenant_id' => 'default',
                'project_key' => 'docs-v3',
                'label' => 'demo-local',
                'allowed_origins' => [
                    'http://127.0.0.1:8000',
                    'http://localhost:8000',
                    'http://localhost:5173',
                ],
                'rate_limit' => 1000,
                'skill' => 'askmydocs-assistant@1',
                'is_active' => true,
            ],
        );
    }

    /**
     * Where the API tools seeded here should send their outbound requests.
     *
     * These tools make the APPLICATION the client: the agent calls them and
     * the server issues a real HTTP request to the URL below. Pointing that at
     * the app's own address made it the client of itself, which PHP's built-in
     * dev server answers with an empty reply (cURL 52) whenever the worker
     * that would serve it is the one already busy issuing it — the tool calls
     * then fail and the agent reports an incomplete search.
     *
     * The E2E environment therefore runs a second instance of the application
     * on another port and sets this variable to it. The default keeps the
     * previous behaviour for anyone running the seeder without that setup.
     */
    private function outboundBase(): string
    {
        return rtrim((string) env('E2E_OUTBOUND_BASE_URL', 'http://127.0.0.1:8000'), '/');
    }

    private function seedScope(string $tenant, string $project, string $name): void
    {
        $connector = ApiConnector::query()->updateOrCreate(
            ['tenant_id' => $tenant, 'project_key' => $project, 'name' => $name],
            [
                'description' => 'Deterministic customer and order lookup for the agent E2E.',
                'base_url' => $this->outboundBase(),
                'headers' => [],
                'is_active' => true,
            ],
        );

        $customer = ApiRoute::query()->updateOrCreate(
            ['tenant_id' => $tenant, 'project_key' => $project, 'slug' => 'find_customer'],
            [
                'api_connector_id' => $connector->id,
                'name' => 'Cerca cliente',
                'description' => 'Find a customer by name before loading their orders.',
                'http_method' => 'GET',
                'url' => $this->outboundBase().'/testing/api-fixture/customers',
                'input_schema' => $this->schema(['name' => ['type' => 'string']]),
                'tool_definition' => [
                    'name' => 'find_customer',
                    'description' => 'Find a customer by name before loading their orders.',
                    'input_schema' => $this->schema(['name' => ['type' => 'string']]),
                ],
                'mode' => 'tool',
                'status' => 'active',
                'endpoint_type' => 'list',
                'endpoint_type_locked' => true,
                'items_path' => 'items',
            ],
        );
        $this->parameter($customer, 'name', 'query', 'llm', 'string', null, true, 0);

        $orders = ApiRoute::query()->updateOrCreate(
            ['tenant_id' => $tenant, 'project_key' => $project, 'slug' => 'get_orders'],
            [
                'api_connector_id' => $connector->id,
                'name' => 'Recupera ordini',
                'description' => 'Load every order for a known customer id.',
                'http_method' => 'GET',
                'url' => $this->outboundBase().'/testing/api-fixture/customers/{customer_id}/orders',
                'input_schema' => $this->schema(['customer_id' => ['type' => 'integer']]),
                'tool_definition' => [
                    'name' => 'get_orders',
                    'description' => 'Load every order for a known customer id.',
                    'input_schema' => $this->schema(['customer_id' => ['type' => 'integer']]),
                ],
                'pagination' => [
                    'type' => 'page',
                    'page_param' => 'page',
                    'size_param' => 'per_page',
                    'start_page' => 1,
                    'items_path' => 'orders',
                ],
                'mode' => 'tool',
                'status' => 'active',
                'endpoint_type' => 'list',
                'endpoint_type_locked' => true,
                'items_path' => 'orders',
            ],
        );
        $this->parameter($orders, 'customer_id', 'path', 'llm', 'integer', null, true, 0);
        $this->parameter($orders, 'per_page', 'query', 'fixed', 'integer', '2', false, 1);
    }

    /** @param array<string,array<string,string>> $properties @return array<string,mixed> */
    private function schema(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    private function parameter(
        ApiRoute $route,
        string $name,
        string $location,
        string $source,
        string $type,
        ?string $value,
        bool $required,
        int $order,
    ): void {
        ApiRouteParameter::query()->updateOrCreate(
            ['tenant_id' => $route->tenant_id, 'api_route_id' => $route->id, 'name' => $name],
            [
                'location' => $location,
                'source' => $source,
                'type' => $type,
                'required' => $required,
                'value' => $value,
                'description' => $source === 'llm' ? 'Value selected by the retrieval planner.' : 'E2E page size.',
                'sort_order' => $order,
            ],
        );
    }
}
