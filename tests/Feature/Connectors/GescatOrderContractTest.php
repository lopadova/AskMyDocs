<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Agent\Planning\AgentPlanArgumentConstraints;
use App\Agent\Planning\AgentPlanValidationException;
use App\Agent\Tools\AgentToolDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Tests\TestCase;

final class GescatOrderContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_is_scoped_idempotent_and_survives_a_connection_retest(): void
    {
        config(['connector-api.ssrf.resolve_dns' => false, 'connector-api.llm_assist.enabled' => false]);
        $route = $this->route('www.autry-usa.com', 'autry');
        $unrelated = $this->route('other.example.com', 'other');
        $projection = $route->output_transform;
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_27_150000_fix_gescat_orders_sort_contract.php';
        $migration->up();
        $first = $route->fresh();
        $migration->up();
        $fixed = $route->fresh();
        $this->assertSame($first->input_schema, $fixed->input_schema);
        $this->assertSame(['data', 'num_fattura'], data_get($fixed->input_schema, 'properties.orderBy.enum'));
        $this->assertSame('data', data_get($fixed->tool_definition, 'input_schema.properties.orderBy.default'));
        $this->assertSame('active', $fixed->status->value);
        $this->assertSame($projection, $fixed->output_transform);
        $this->assertSame('type', $fixed->parameters()->first()->name);
        $this->assertSame(0, $fixed->parameters()->min('sort_order'));
        $this->assertArrayNotHasKey('enum', $unrelated->fresh()->input_schema['properties']['orderBy']);
        $this->assertStringContainsString('Valori ammessi: data, num_fattura', $fixed->parameters()->where('name', 'orderBy')->sole()->description);

        Http::fake(['www.autry-usa.com/*' => Http::response(['order' => [['ord_ID' => 1, 'ord_codiceweb' => 'DEMO-1']]])]);
        $outcome = app(ConnectorAdminService::class)->testRoute($fixed, ['limit' => 10, 'orderBy' => 'data']);
        $this->assertTrue($outcome['result']->ok);
        $retested = $route->fresh();
        $this->assertSame($fixed->input_schema['properties']['orderBy'], $retested->input_schema['properties']['orderBy']);
        $this->assertSame(['data', 'num_fattura'], data_get($retested->tool_definition, 'input_schema.properties.orderBy.enum'));
        Http::assertSent(fn ($request) => $request['limit'] === 10 && $request['orderBy'] === 'data');
    }

    public function test_the_classic_planner_rejects_the_original_invalid_sort_before_any_http_request(): void
    {
        $route = $this->route('www.autry-usa.com', 'autry');
        (require dirname(__DIR__, 3).'/database/migrations/2026_10_27_150000_fix_gescat_orders_sort_contract.php')->up();
        $tool = new AgentToolDefinition('gescat_cerca_ordini', 'Orders', 'Orders', 'api', $route->fresh()->tool_definition['input_schema'], true, true, 1, 1, 1);
        $guard = new AgentPlanArgumentConstraints;
        $guard->validateArguments($tool, ['limit' => 10, 'orderBy' => 'data']);
        Http::preventStrayRequests();
        try {
            $guard->validateArguments($tool, ['limit' => 10, 'orderBy' => 'created_at desc']);
            $this->fail('Invalid SQL-style sorting was accepted.');
        } catch (AgentPlanValidationException $failure) {
            $this->assertSame('input_pattern_mismatch', $failure->validationCode);
        }
        Http::assertNothingSent();
    }

    private function route(string $host, string $tenant): ApiRoute
    {
        $schema = ['type' => 'object', 'properties' => ['orderBy' => ['type' => 'string'], 'limit' => ['type' => 'integer']], 'required' => []];
        $connector = ApiConnector::create(['tenant_id' => $tenant, 'name' => 'Orders', 'is_active' => true]);
        $route = ApiRoute::create(['tenant_id' => $tenant, 'api_connector_id' => $connector->id, 'project_key' => '',
            'name' => 'Orders', 'slug' => 'gescat_cerca_ordini', 'url' => 'https://'.$host.'/api/admin/ws/ws_orders',
            'http_method' => 'GET', 'status' => 'active', 'mode' => 'tool', 'input_schema' => $schema,
            'output_transform' => ['include' => ['order.*.ord_ID', 'order.*.ord_codiceweb']],
            'tool_definition' => ['name' => 'gescat_cerca_ordini', 'description' => 'Orders', 'input_schema' => $schema]]);
        foreach (['orderBy' => 'string', 'limit' => 'integer'] as $name => $type) {
            ApiRouteParameter::create(['tenant_id' => $tenant, 'api_route_id' => $route->id, 'name' => $name,
                'location' => 'query', 'source' => 'llm', 'type' => $type, 'required' => false]);
        }
        ApiRouteParameter::create(['tenant_id' => $tenant, 'api_route_id' => $route->id, 'name' => 'type',
            'location' => 'query', 'source' => 'fixed', 'type' => 'string', 'value' => 'json', 'sort_order' => -1]);
        return $route;
    }
}
