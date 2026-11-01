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

    public function test_order_detail_identity_repair_preserves_other_services_and_explicit_configuration(): void
    {
        Http::preventStrayRequests();
        $detail = $this->route('www.autry-usa.com', 'autry');
        $detail->update(['slug' => 'gescat_dettaglio_ordine', 'endpoint_type' => 'detail', 'identity_fields' => []]);
        $other = $this->route('other.example.com', 'other');
        $other->update(['slug' => 'gescat_dettaglio_ordine']);
        $custom = $this->route('www.autry-usa.com', 'custom');
        $custom->update(['slug' => 'gescat_dettaglio_ordine', 'identity_fields' => ['custom_reference']]);
        $list = $this->route('www.autry-usa.com', 'list');
        $before = $detail->fresh()->only(['status', 'mode', 'endpoint_type', 'url', 'output_transform', 'input_schema']);
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_01_130000_fix_gescat_order_detail_identity.php';
        $migration->up();
        $migration->up();
        $fixed = $detail->fresh();
        $this->assertSame(['ord_codiceweb', 'ord_ID'], $fixed->identity_fields);
        $this->assertSame($before, $fixed->only(array_keys($before)));
        $this->assertEmpty($other->fresh()->identity_fields);
        $this->assertEmpty($list->fresh()->identity_fields);
        $this->assertSame(['custom_reference'], $custom->fresh()->identity_fields);

        $evidence = ['api_tools' => [['execution_id' => 1, 'evidence_hash' => 'verified',
            'presentation' => ['collection_path' => 'order', 'identity_fields' => $fixed->identity_fields],
            'result' => ['order' => [['ord_ID' => 42, 'ord_codiceweb' => 'ORDER-A', 'stato_ordine' => 'SPEDITO']]],
        ]]];
        $projector = new \App\Services\Chat\Reasoning\EvidenceProjector;
        $claim = ['tool_execution_id' => 1, 'evidence_hash' => 'verified', 'record_path' => 'order.0'];
        $this->assertSame('SPEDITO', $projector->bind($evidence, $claim, ['ORDER-A'], 'autry', '')['record']['stato_ordine']);
        $this->assertSame('entity_mismatch', $projector->bind($evidence, $claim, ['ORDER-B'], 'autry', '')['reason']);
        $migration->down();
        $this->assertSame(['ord_codiceweb', 'ord_ID'], $detail->fresh()->identity_fields);
        Http::assertNothingSent();
    }

    public function test_business_field_declarations_are_scoped_idempotent_and_preserve_manual_roles(): void
    {
        Http::preventStrayRequests();
        $properties = [];
        foreach (['ord_codiceweb', 'stato_ordine', 'valute_codice', 'ord_data', 'ipaddress'] as $field) $properties[$field] = ['type' => 'string'];
        $properties['ord_totale_finale'] = ['type' => 'integer'];
        $schema = ['type' => 'object', 'properties' => ['order' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $properties]]]];
        $route = $this->route('www.autry-usa.com', 'autry');
        $route->update(['output_schema' => $schema]);
        $other = $this->route('other.example.com', 'other');
        $other->update(['output_schema' => $schema]);
        $custom = $this->route('www.autry-usa.com', 'custom');
        $manual = $schema;
        $manual['properties']['order']['items']['properties']['stato_ordine']['x-field'] = 'manual_state';
        $manual['properties']['order']['items']['properties']['external_state'] = ['type' => 'string', 'x-field' => 'status'];
        $custom->update(['output_schema' => $manual]);
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_01_140000_declare_gescat_order_business_fields.php';
        $migration->up();
        $fixed = $route->fresh()->output_schema;
        $migration->up();
        $this->assertSame($fixed, $route->fresh()->output_schema);
        $this->assertSame('status', data_get($fixed, 'properties.order.items.properties.stato_ordine.x-field'));
        $this->assertSame('total', data_get($fixed, 'properties.order.items.properties.ord_totale_finale.x-field'));
        $this->assertArrayNotHasKey('x-field', $fixed['properties']['order']['items']['properties']['ipaddress']);
        $this->assertSame($schema, $other->fresh()->output_schema);
        $this->assertSame('manual_state', data_get($custom->fresh()->output_schema, 'properties.order.items.properties.stato_ordine.x-field'));
        $tool = new AgentToolDefinition('orders', 'Orders', 'Orders', 'api', [], true, true, 1, 1, 1,
            metadata: ['items_path' => 'order', 'identity_fields' => ['ord_codiceweb'], 'output_schema' => $fixed]);
        $envelope = app(\App\Agent\Evidence\AgentEvidenceFactory::class)->empty();
        $envelope->addToolResult($tool, [], ['order' => [['ord_codiceweb' => 'ORDER-A', 'stato_ordine' => 'SPEDITO',
            'ord_totale_finale' => 180, 'valute_codice' => 'EUR', 'ord_data' => '2026-04-02', 'ipaddress' => '192.0.2.20']]], 42);
        $this->assertSame(['reference' => 'ord_codiceweb', 'status' => 'stato_ordine', 'currency' => 'valute_codice',
            'date' => 'ord_data', 'total' => 'ord_totale_finale'], $envelope->apiTools()[0]['presentation']['field_aliases']);
        $this->assertSame('192.0.2.20', $envelope->apiTools()[0]['result']['order'][0]['ipaddress']); // The full original stays intact.
        $migration->down();
        $this->assertSame($fixed, $route->fresh()->output_schema);
        Http::assertNothingSent();
    }

    public function test_item_declarations_keep_nested_business_rows_and_preserve_other_tenants_and_manual_roles(): void
    {
        Http::preventStrayRequests();
        $schema = ['type' => 'object', 'properties' => ['order' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'ord_codiceweb' => ['type' => 'string'],
            'articoli' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'articolo' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'art_pcode' => ['type' => 'string'], 'DescArticolo' => ['type' => 'string'],
                    'variante' => ['type' => 'integer'], 'art_qta' => ['type' => 'integer'], 'internal_margin' => ['type' => 'number'],
                ]]],
            ]]],
        ]]]]];
        $route = $this->route('www.autry-usa.com', 'autry');
        $route->update(['output_schema' => $schema]);
        $otherHost = $this->route('other.example.com', 'autry', 'other-host');
        $otherHost->update(['output_schema' => $schema]);
        $otherTenant = $this->route('www.autry-usa.com', 'other');
        $otherTenant->update(['output_schema' => $schema]);
        $manual = $this->route('www.autry-usa.com', 'autry', 'manual');
        $manualSchema = $schema;
        data_set($manualSchema, 'properties.order.items.properties.articoli.items.properties.articolo.x-field', 'custom_items');
        $manual->update(['output_schema' => $manualSchema]);
        $before = $route->fresh()->only(['url', 'input_schema', 'output_transform', 'identity_fields', 'status']);
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_01_170000_declare_gescat_order_item_fields.php';
        $migration->up();
        $fixed = $route->fresh()->output_schema;
        $migration->up();
        $this->assertSame($fixed, $route->fresh()->output_schema);
        $this->assertSame($before, $route->fresh()->only(array_keys($before)));
        $this->assertSame($schema, $otherHost->fresh()->output_schema);
        $this->assertSame($schema, $otherTenant->fresh()->output_schema);
        $this->assertSame($manualSchema, $manual->fresh()->output_schema);
        $tool = new AgentToolDefinition('orders', 'Orders', 'Orders', 'api', [], true, true, 1, 1, 1,
            metadata: ['items_path' => 'order', 'identity_fields' => ['ord_codiceweb'], 'output_schema' => $fixed]);
        $envelope = app(\App\Agent\Evidence\AgentEvidenceFactory::class)->empty();
        $envelope->addToolResult($tool, [], ['order' => [['ord_codiceweb' => 'ORDER-A', 'articoli' => [['articolo' => [[
            'art_pcode' => 'SKU-A', 'DescArticolo' => 'Shoe', 'variante' => 40, 'art_qta' => 1, 'internal_margin' => 97,
        ]]]]]]], 42);
        $declarations = $envelope->apiTools()[0]['presentation']['record_collections'];
        $this->assertSame('articoli.*.articolo.*', $declarations[0]['path']);
        $this->assertSame(['reference' => 'art_pcode', 'description' => 'DescArticolo', 'variant' => 'variante', 'quantity' => 'art_qta'], $declarations[0]['fields']);
        $this->assertStringNotContainsString('internal_margin', json_encode($declarations));
        $this->assertSame(97, $envelope->apiTools()[0]['result']['order'][0]['articoli'][0]['articolo'][0]['internal_margin']);
        $migration->down();
        $this->assertSame($fixed, $route->fresh()->output_schema);
        Http::assertNothingSent();
    }

    private function route(string $host, string $tenant, string $project = ''): ApiRoute
    {
        $schema = ['type' => 'object', 'properties' => ['orderBy' => ['type' => 'string'], 'limit' => ['type' => 'integer']], 'required' => []];
        $connector = ApiConnector::create(['tenant_id' => $tenant, 'name' => 'Orders', 'is_active' => true]);
        $route = ApiRoute::create(['tenant_id' => $tenant, 'api_connector_id' => $connector->id, 'project_key' => $project,
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
