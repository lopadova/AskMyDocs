<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Agent\AgentExecutionContext;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Tools\AgentToolRegistry;
use App\ApiConnectors\ConnectorConfigTransferService;
use App\ApiTools\ContentHasher;
use App\Models\User;
use App\Services\Chat\Reasoning\EvidenceProjector;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Tests\TestCase;

final class ApiEndpointIdentityFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => 'endpoint-identities@example.test', 'password' => bcrypt('password')]);
        $user->assignRole('admin');
        $this->actingAs($user);
        config(['connector-api.ssrf.resolve_dns' => false, 'connector-api.llm_assist.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_identity_fields_are_saved_and_reopened_for_every_mode_and_response_shape(): void
    {
        $connector = $this->connector();
        foreach (['tool', 'ingest', 'both'] as $mode) {
            foreach (['list', 'detail'] as $shape) {
                $config = $this->config($mode, $shape);
                $created = $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes', ['config' => $config])
                    ->assertCreated()->assertJsonPath('data.identity_fields', ['ord_codiceweb', 'ord_ID'])
                    ->assertJsonPath('data.config.response.identity_fields', ['ord_codiceweb', 'ord_ID']);
                $id = $created->json('data.id');
                $this->getJson('/api/admin/api-connectors/routes/'.$id)->assertOk()
                    ->assertJsonPath('data.mode', $mode)->assertJsonPath('data.endpoint_type', $shape)
                    ->assertJsonPath('data.config.response.identity_fields', ['ord_codiceweb', 'ord_ID']);
                $this->assertSame(['ord_codiceweb', 'ord_ID'], ApiRoute::findOrFail($id)->identity_fields);
            }
        }
        Http::assertNothingSent();
    }

    public function test_flat_api_updates_preserve_omitted_fields_and_allow_replacement_and_clearing(): void
    {
        $connector = $this->connector();
        $created = $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes', [
            'name' => 'API orders', 'http_method' => 'GET', 'url' => 'https://api.example.com/orders',
            'mode' => 'ingest', 'identity_fields' => ['ord_codiceweb'],
        ])->assertCreated();
        $url = '/api/admin/api-connectors/routes/'.$created->json('data.id');
        $this->patchJson($url, ['description' => 'Updated'])->assertOk()->assertJsonPath('data.identity_fields', ['ord_codiceweb']);
        $this->patchJson($url, ['identity_fields' => ['ord_ID']])->assertOk()->assertJsonPath('data.identity_fields', ['ord_ID']);
        $this->patchJson($url, ['identity_fields' => []])->assertOk()->assertJsonPath('data.config.response.identity_fields', []);
    }

    public function test_invalid_or_nested_identity_fields_are_rejected_by_both_write_formats(): void
    {
        $connector = $this->connector();
        foreach ([['Customer.id'], ['order.*.code'], [''], ['id', 'id'], ['id' => 'code'], [42], array_map(fn ($n) => 'id_'.$n, range(1, 13))] as $fields) {
            $config = $this->config();
            $config['response']['identity_fields'] = $fields;
            $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes', ['config' => $config])->assertUnprocessable();
            $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes', [
                'name' => 'Invalid', 'http_method' => 'GET', 'url' => 'https://api.example.com/orders', 'identity_fields' => $fields,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('api_routes', 0);
    }

    public function test_retesting_and_automatic_configuration_preserve_manual_identity_fields(): void
    {
        $connector = $this->connector();
        $config = $this->config();
        $created = $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes', ['config' => $config])->assertCreated();
        Http::fake(['api.example.com/*' => Http::response(['order' => [['ord_ID' => 1041068, 'ord_codiceweb' => 'U532638']]])]);
        $id = $created->json('data.id');
        $this->postJson('/api/admin/api-connectors/routes/'.$id.'/test', ['example_args' => []])->assertOk()->assertJsonPath('test.ok', true);
        $this->getJson('/api/admin/api-connectors/routes/'.$id)->assertOk()
            ->assertJsonPath('data.config.response.identity_fields', ['ord_codiceweb', 'ord_ID']);
        $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes/produce-config', ['config' => $config, 'example_args' => []])
            ->assertOk()->assertJsonPath('config.response.identity_fields', ['ord_codiceweb', 'ord_ID']);
    }

    public function test_saved_configuration_reaches_grounding_for_list_and_detail_and_rejects_other_entities(): void
    {
        $connector = $this->connector();
        foreach (['list', 'detail'] as $shape) {
            $created = $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes', ['config' => $this->config('tool', $shape)])->assertCreated();
            $route = ApiRoute::findOrFail($created->json('data.id'));
            $route->update(['status' => 'active']);
            $context = new AgentExecutionContext('identities-'.$shape, $connector->tenant_id, null, 'chat', 'user', '1', 'it', 'Europe/Rome');
            $tool = app(AgentToolRegistry::class)->forContext($context)[$route->slug];
            $record = ['ord_ID' => 1041068, 'ord_codiceweb' => 'U532638', 'stato_ordine' => 'SPEDITO', 'customerId' => 'U999999',
                'products' => [['ord_codiceweb' => 'U999999']],
            ];
            $envelope = app(AgentEvidenceFactory::class)->empty();
            $envelope->addToolResult($tool, ['ord_codiceweb' => 'U532638'], $shape === 'list' ? ['order' => [$record]] : $record, 322);
            $evidence = $envelope->jsonSerialize();
            $this->assertSame(['ord_codiceweb', 'ord_ID'], $evidence['api_tools'][0]['presentation']['identity_fields']);
            $claim = ['tool_execution_id' => 322, 'evidence_hash' => $evidence['api_tools'][0]['evidence_hash']];
            $projector = new EvidenceProjector;
            $bound = $projector->bind($evidence, $claim, ['U532638'], $connector->tenant_id, '');
            $this->assertSame('SPEDITO', $bound['record']['stato_ordine']);
            $this->assertSame('entity_mismatch', $projector->bind($evidence, $claim, ['U999999'], $connector->tenant_id, '')['reason']);
            $claim['evidence_hash'] = 'wrong-hash';
            $this->assertSame('source_hash_mismatch', $projector->bind($evidence, $claim, ['U532638'], $connector->tenant_id, '')['reason']);
        }
    }

    public function test_export_and_import_keep_identity_fields_and_reject_invalid_imports(): void
    {
        $connector = $this->connector();
        $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes', ['config' => $this->config('ingest')])->assertCreated();
        $config = app(ConnectorConfigTransferService::class)->export($connector->id);
        $this->assertSame(['ord_codiceweb', 'ord_ID'], $config['routes'][0]['identity_fields']);
        $copy = $this->postJson('/api/admin/api-connectors/import', ['config' => $config])->assertCreated();
        $route = ApiConnector::findOrFail($copy->json('data.id'))->routes()->firstOrFail();
        $this->assertSame(['ord_codiceweb', 'ord_ID'], $route->identity_fields);
        $config['routes'][0]['identity_fields'] = ['Customer.id'];
        $this->postJson('/api/admin/api-connectors/import', ['config' => $config])->assertUnprocessable();
    }

    public function test_changing_identity_fields_changes_the_endpoint_dependency_hash(): void
    {
        $connector = $this->connector();
        $created = $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes', ['config' => $this->config()])->assertCreated();
        $route = ApiRoute::findOrFail($created->json('data.id'));
        $hasher = app(ContentHasher::class);
        $before = $hasher->endpoint($route);
        $route->update(['identity_fields' => ['ord_codiceweb']]);
        $this->assertNotSame($before, $hasher->endpoint($route->fresh()));
    }

    public function test_identity_migration_rolls_back_without_losing_endpoints(): void
    {
        $connector = $this->connector();
        $created = $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes', ['config' => $this->config()])->assertCreated();
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_27_100000_add_identity_fields_to_api_routes_table.php';
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('api_routes', 'identity_fields'));
        $this->assertDatabaseHas('api_routes', ['id' => $created->json('data.id')]);
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('api_routes', 'identity_fields'));
        $this->getJson('/api/admin/api-connectors/routes/'.$created->json('data.id'))->assertOk()->assertJsonPath('data.identity_fields', []);
    }

    private function connector(): ApiConnector
    {
        return ApiConnector::create(['tenant_id' => app(TenantContext::class)->current(), 'project_key' => '', 'name' => 'GESCAT', 'is_active' => true]);
    }

    private function config(string $mode = 'tool', string $shape = 'list'): array
    {
        return [
            'identity' => ['name' => 'Orders '.$mode.' '.$shape, 'mode' => $mode],
            'request' => ['http_method' => 'GET', 'url' => 'https://api.example.com/orders', 'params' => []],
            'response' => ['endpoint_type' => $shape, 'items_path' => $shape === 'list' ? 'order' : null, 'identity_fields' => ['ord_codiceweb', 'ord_ID']],
        ];
    }
}
