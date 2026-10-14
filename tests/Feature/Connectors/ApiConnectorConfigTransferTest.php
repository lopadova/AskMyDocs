<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\ApiConnectors\ConnectorConfigTransferService;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Tests\TestCase;

final class ApiConnectorConfigTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->actingAs($this->user('super-admin'));
        config()->set('connector-api.ssrf.resolve_dns', false);
    }

    public function test_export_contains_the_complete_configuration_without_credentials_or_history(): void
    {
        $connector = $this->fixture();
        $response = $this->getJson('/api/admin/api-connectors/'.$connector->id.'/export')->assertOk();
        $config = $response->json('data');
        $this->assertSame(ConnectorConfigTransferService::FORMAT, $config['_meta']['format']);
        $this->assertCount(2, $config['routes']);
        $this->assertCount(1, $config['relations']);
        $this->assertSame('active', $config['routes'][0]['status']);
        $this->assertSame(['type' => 'object'], $config['routes'][0]['input_schema']);
        $this->assertSame(['type' => 'offset', 'limit_param' => 'limit'], $config['routes'][0]['pagination']);
        $this->assertSame(5000, $config['routes'][0]['timeout_ms']);
        $this->assertSame('fixed', $config['routes'][0]['parameters'][0]['source']);
        $this->assertSame('v2', $config['routes'][0]['parameters'][0]['value']);
        $this->assertSame($config['auth_profiles'][0]['ref'], $config['default_auth_profile_ref']);
        $this->assertSame($config['routes'][1]['ref'], $config['relations'][0]['detail_route_ref']);
        $this->assertArrayNotHasKey('credentials', $config['auth_profiles'][0]);
        $this->assertArrayNotHasKey('last_test_payload', $config['routes'][0]);
        $this->assertArrayNotHasKey('tenant_id', $config['connector']);
        $this->assertStringNotContainsString('original-secret', $response->getContent());
        $this->assertStringNotContainsString('private-test-response', $response->getContent());
    }

    public function test_import_restores_endpoints_profiles_relations_and_runtime_with_new_secrets(): void
    {
        $original = $this->fixture();
        $config = app(ConnectorConfigTransferService::class)->export($original->id);
        $ref = $config['auth_profiles'][0]['ref'];
        $preview = $this->postJson('/api/admin/api-connectors/import/validate', ['config' => $config])->assertOk();
        $this->assertSame(['api_key'], array_column($preview->json('data.required_credentials'), 'key'));
        $response = $this->postJson('/api/admin/api-connectors/import', [
            'config' => $config, 'credentials' => [$ref => ['api_key' => 'replacement-secret']],
        ])->assertCreated();
        $copy = ApiConnector::findOrFail($response->json('data.id'));
        $this->assertSame('Orders (2)', $copy->name);
        $this->assertNotSame($original->default_auth_profile_id, $copy->default_auth_profile_id);
        $this->assertSame('replacement-secret', $copy->defaultAuthProfile()->credential('api_key'));
        $this->assertStringNotContainsString('replacement-secret', $response->getContent());
        $this->assertStringNotContainsString('replacement-secret', $copy->defaultAuthProfile()->getRawOriginal('credentials'));
        $list = $copy->routes()->where('endpoint_type', 'list')->firstOrFail();
        $detail = $copy->routes()->where('endpoint_type', 'detail')->firstOrFail();
        $this->assertSame('list_orders_2', $list->slug);
        $this->assertSame($list->slug, $list->tool_definition['name']);
        $this->assertStringContainsString('detail_order_2', $list->tool_definition['description']);
        $this->assertSame($copy->default_auth_profile_id, $detail->auth_profile_id);
        $this->assertSame('active', $list->status->value);
        $this->assertNull($list->last_test_payload);
        $relation = $copy->relations()->firstOrFail();
        $this->assertSame($list->id, $relation->list_route_id);
        $this->assertSame($detail->id, $relation->detail_route_id);
        config()->set('connector-api.ssrf.resolve_dns', false);
        Http::fake(['api.example.com/*' => Http::response(['orders' => [['id' => 42]]])]);
        $result = app(ApiToolExecutor::class)->execute($list->load('parameters', 'connector'), []);
        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'replacement-secret'));
    }

    public function test_missing_secrets_reject_the_import_before_any_rows_are_created(): void
    {
        $connector = $this->fixture();
        $config = app(ConnectorConfigTransferService::class)->export($connector->id);
        $this->postJson('/api/admin/api-connectors/import', ['config' => $config])->assertUnprocessable()
            ->assertJsonValidationErrors('credentials.'.$config['auth_profiles'][0]['ref'].'.api_key');
        $this->assertDatabaseCount('api_connectors', 1);
        $this->assertDatabaseCount('api_routes', 2);
        $this->assertDatabaseCount('api_auth_profiles', 1);
    }

    public function test_all_auth_types_request_only_the_missing_material_and_restore_it(): void
    {
        $cases = [
            ['none', [], []],
            ['api_key', ['key' => 'original-secret'], ['key']],
            ['bearer', ['access_token' => 'original-secret'], ['access_token']],
            ['basic', ['username' => 'alice', 'password' => 'original-secret'], ['password']],
            ['custom', ['headers' => ['X-Signature' => 'original-secret', 'X-Tenant-Key' => 'original-secret']], ['header:X-Signature', 'header:X-Tenant-Key']],
            ['custom', ['value' => 'original-secret'], ['value']],
            ['oauth2_cc', ['client_id' => 'client-123', 'client_secret' => 'original-secret'], ['client_secret']],
        ];
        foreach ($cases as $index => [$type, $credentials, $expected]) {
            $connector = app(ConnectorAdminService::class)->createConnector(['name' => $type.'-'.$index]);
            $profile = app(ConnectorAdminService::class)->createAuthProfile($connector, [
                'type' => $type, 'credentials' => $credentials,
                'config' => $type === 'oauth2_cc' ? ['token_url' => 'https://auth.example.com/token', 'scope' => 'read'] : [],
            ]);
            $connector->update(['default_auth_profile_id' => $profile->id]);
            $config = app(ConnectorConfigTransferService::class)->export($connector->id);
            $preview = $this->postJson('/api/admin/api-connectors/import/validate', ['config' => $config])->assertOk();
            $this->assertSame($expected, array_column($preview->json('data.required_credentials'), 'key'), $type);
            $this->assertStringNotContainsString('original-secret', json_encode($config));
            $values = array_fill_keys($expected, 'replacement-secret');
            $response = $this->postJson('/api/admin/api-connectors/import', [
                'config' => $config, 'credentials' => ['auth-'.$profile->id => $values],
            ])->assertCreated();
            $copyProfile = ApiConnector::findOrFail($response->json('data.id'))->defaultAuthProfile();
            if ($type === 'basic') $this->assertSame('alice', $copyProfile->credential('username'));
            if ($type === 'oauth2_cc') $this->assertSame('client-123', $copyProfile->credential('client_id'));
            if ($type === 'custom') {
                $header = isset($credentials['headers']) ? 'X-Signature' : 'X-Custom-Auth';
                $this->assertSame('replacement-secret', $copyProfile->credentials['headers'][$header]);
            }
        }
    }

    public function test_a_failure_during_endpoint_creation_rolls_back_the_entire_import(): void
    {
        $connector = $this->fixture();
        $config = app(ConnectorConfigTransferService::class)->export($connector->id);
        $created = 0;
        Event::listen('eloquent.creating: '.ApiRoute::class, function () use (&$created): void {
            if (++$created === 2) throw new \RuntimeException('Simulated database failure.');
        });
        try {
            app(ConnectorConfigTransferService::class)->import($config, [
                $config['auth_profiles'][0]['ref'] => ['api_key' => 'new-secret'],
            ]);
            $this->fail('An endpoint creation failure must abort the import.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated database failure.', $exception->getMessage());
        }
        $this->assertDatabaseCount('api_connectors', 1);
        $this->assertDatabaseCount('api_auth_profiles', 1);
        $this->assertDatabaseCount('api_routes', 2);
        $this->assertDatabaseCount('api_route_parameters', 2);
        $this->assertDatabaseCount('api_route_relations', 1);
    }

    public function test_an_observer_cannot_silently_skip_an_endpoint_and_leave_a_partial_import(): void
    {
        $connector = $this->fixture();
        $config = app(ConnectorConfigTransferService::class)->export($connector->id);
        Event::listen('eloquent.creating: '.ApiRoute::class, fn () => false);
        try {
            app(ConnectorConfigTransferService::class)->import($config, [
                $config['auth_profiles'][0]['ref'] => ['api_key' => 'new-secret'],
            ]);
            $this->fail('A cancelled save must abort the import.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failed to persist ApiRoute.', $exception->getMessage());
        }
        $this->assertDatabaseCount('api_connectors', 1);
        $this->assertDatabaseCount('api_auth_profiles', 1);
        $this->assertDatabaseCount('api_routes', 2);
    }

    public function test_secret_parameters_use_the_effective_profile_and_shared_headers_are_reentered(): void
    {
        $connector = $this->fixture();
        $connector->update(['headers' => ['Accept' => 'application/json', 'Authorization' => 'original-secret']]);
        $route = $connector->routes()->firstOrFail();
        $route->parameters()->create(['tenant_id' => $connector->tenant_id, 'name' => 'signature',
            'location' => 'header', 'source' => 'secret', 'type' => 'string', 'required' => true,
            'secret_ref' => 'signature', 'value' => 'original-secret']);
        $config = app(ConnectorConfigTransferService::class)->export($connector->id);
        $this->assertStringNotContainsString('original-secret', json_encode($config));
        $preview = $this->postJson('/api/admin/api-connectors/import/validate', ['config' => $config])->assertOk();
        $this->assertSame(['api_key', 'signature', 'Authorization'], array_column($preview->json('data.required_credentials'), 'key'));
        $response = $this->postJson('/api/admin/api-connectors/import', ['config' => $config, 'credentials' => [
            $config['auth_profiles'][0]['ref'] => ['api_key' => 'new-key', 'signature' => 'new-signature'],
            'shared-headers' => ['Authorization' => 'new-header'],
        ]])->assertCreated();
        $copy = ApiConnector::findOrFail($response->json('data.id'));
        $this->assertSame('new-header', $copy->headers['Authorization']);
        $this->assertSame('new-signature', $copy->defaultAuthProfile()->credential('signature'));
    }

    public function test_invalid_files_and_cross_file_references_cannot_create_partial_data(): void
    {
        $connector = $this->fixture();
        $config = app(ConnectorConfigTransferService::class)->export($connector->id);
        foreach ([
            ['_meta.format', 'wrong-format'], ['_meta.version', 2],
            ['default_auth_profile_ref', 'auth-foreign'], ['routes.0.auth_profile_ref', 'auth-foreign'],
            ['relations.0.detail_route_ref', 'foreign-route'], ['routes.0.http_method', 'INVALID'],
            ['relations.0.field_map.0.to_param', 'missing'],
            ['routes.0.parameters.0.source', null], ['routes.0.parameters.0.required', null],
            ['routes.0.parameters.0.type', 'invalid'], ['auth_profiles.0.config.header_name', []],
        ] as [$key, $value]) {
            $invalid = $config;
            data_set($invalid, $key, $value);
            $this->postJson('/api/admin/api-connectors/import', ['config' => $invalid])->assertUnprocessable();
        }
        $this->assertDatabaseCount('api_connectors', 1);
        $this->assertDatabaseCount('api_routes', 2);
    }

    public function test_export_is_tenant_scoped_and_import_uses_the_destination_tenant(): void
    {
        $connector = $this->fixture();
        $config = app(ConnectorConfigTransferService::class)->export($connector->id);
        app(TenantContext::class)->set('destination');
        // Testbench omits the production global ResolveTenant middleware.
        app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->set('destination');
        $this->actingAs($this->user('super-admin'));
        $this->withHeaders(['X-Tenant-ID' => 'destination']);
        $this->getJson('/api/admin/api-connectors/'.$connector->id.'/export')->assertNotFound();
        $config['connector']['tenant_id'] = 'foreign';
        $response = $this->postJson('/api/admin/api-connectors/import', ['config' => $config,
            'credentials' => [$config['auth_profiles'][0]['ref'] => ['api_key' => 'new-secret']],
        ])->assertCreated();
        $copy = ApiConnector::findOrFail($response->json('data.id'));
        $this->assertSame('destination', $copy->tenant_id);
        $this->assertSame('destination', $copy->routes()->firstOrFail()->tenant_id);
        $this->assertSame('destination', $copy->defaultAuthProfile()->tenant_id);
    }

    public function test_all_transfer_endpoints_require_connector_management_permission(): void
    {
        $connector = $this->fixture();
        $this->actingAs($this->user('viewer'));
        $this->getJson('/api/admin/api-connectors/'.$connector->id.'/export')->assertForbidden();
        $this->postJson('/api/admin/api-connectors/import/validate', [])->assertForbidden();
        $this->postJson('/api/admin/api-connectors/import', [])->assertForbidden();
        auth()->forgetGuards();
        $this->getJson('/api/admin/api-connectors/'.$connector->id.'/export')->assertUnauthorized();
        $this->postJson('/api/admin/api-connectors/import', [])->assertUnauthorized();
    }

    private function fixture(): ApiConnector
    {
        $admin = app(ConnectorAdminService::class);
        $connector = $admin->createConnector(['name' => 'Orders', 'base_url' => 'https://api.example.com',
            'description' => 'Order tools', 'headers' => ['Accept' => 'application/json'], 'is_active' => true]);
        $profile = $admin->createAuthProfile($connector, ['type' => 'api_key', 'credentials' => ['api_key' => 'original-secret'],
            'config' => ['in' => 'header', 'name' => 'X-API-Key']]);
        $connector->update(['default_auth_profile_id' => $profile->id]);
        $list = $admin->createRoute($connector, ['name' => 'Orders', 'slug' => 'list_orders', 'http_method' => 'GET',
            'url' => 'https://api.example.com/orders', 'mode' => 'tool', 'endpoint_type' => 'list', 'items_path' => 'orders',
            'timeout_ms' => 5000, 'cache_ttl_s' => 0, 'rate_limit' => 50,
            'parameters' => [['name' => 'version', 'location' => 'query', 'source' => 'fixed', 'type' => 'string', 'value' => 'v2']],
            'pagination' => ['type' => 'offset', 'limit_param' => 'limit'],
        ]);
        $list->update(['status' => 'active', 'input_schema' => ['type' => 'object'], 'output_schema' => ['type' => 'array'],
            'tool_definition' => ['name' => 'list_orders', 'description' => 'List orders then use detail_order.', 'input_schema' => ['type' => 'object']],
            'last_test_payload' => ['private-test-response' => true]]);
        $detail = $admin->createRoute($connector, ['name' => 'Order', 'slug' => 'detail_order', 'http_method' => 'GET',
            'url' => 'https://api.example.com/orders/{id}', 'mode' => 'tool', 'endpoint_type' => 'detail',
            'auth_profile_id' => $profile->id, 'parameters' => [['name' => 'id', 'location' => 'path', 'source' => 'llm', 'type' => 'integer', 'required' => true]]]);
        $connector->relations()->create(['tenant_id' => $connector->tenant_id, 'list_route_id' => $list->id,
            'detail_route_id' => $detail->id, 'name' => 'Order detail', 'field_map' => [['from' => 'id', 'to_param' => 'id', 'to_location' => 'path']]]);

        return $connector;
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => $role, 'email' => uniqid().'@demo.local', 'password' => bcrypt('password')]);
        $user->assignRole($role);

        return $user;
    }
}
