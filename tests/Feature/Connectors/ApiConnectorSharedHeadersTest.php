<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\ApiConnectors\ConnectorConfigTransferService;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiToolCallLog;
use Padosoft\AskMyDocsConnectorApi\Services\ApiRouteTester;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Tests\TestCase;

final class ApiConnectorSharedHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'X-AskMyDocs-Cloudflare-Token';

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([\Illuminate\Auth\Events\Registered::class]);
        $this->seed(RbacSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => 'headers@demo.local', 'password' => bcrypt('password')]);
        $user->assignRole('super-admin');
        $this->actingAs($user);
        config()->set('connector-api.ssrf.resolve_dns', false);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    public function test_saved_header_is_sent_for_tests_draft_configs_and_live_tools_with_existing_auth(): void
    {
        $connector = $this->createConnector();
        $admin = app(ConnectorAdminService::class);
        $profile = $admin->createAuthProfile($connector, [
            'type' => 'bearer', 'credentials' => ['token' => 'auth-secret'],
        ]);
        $connector->update(['default_auth_profile_id' => $profile->id]);
        $route = $this->createRoute($connector);

        $this->assertTrue(app(ApiRouteTester::class)->test($route)->ok);
        $draft = $admin->testConfig($connector, [
            'identity' => ['name' => 'Draft', 'mode' => 'tool'],
            'request' => ['http_method' => 'GET', 'url' => 'https://api.example.com/draft', 'params' => []],
            'response' => ['endpoint_type' => 'auto'], 'options' => [],
        ]);
        $this->assertTrue($draft['test']->ok);
        $this->assertSame(['ok' => true], app(ApiToolExecutor::class)->execute($route, []));

        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->hasHeader(self::HEADER, 'gateway-secret'));
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer auth-secret'));
        }
        $export = app(ConnectorConfigTransferService::class)->export($connector->id);
        $this->assertStringNotContainsString('gateway-secret', json_encode($export));
        $this->assertContains(self::HEADER, $export['connector']['secret_headers']);
        $this->assertStringNotContainsString('gateway-secret', json_encode($route->tool_definition));
        $logs = ApiToolCallLog::where('api_route_id', $route->id)->get();
        $this->assertCount(1, $logs);
        $this->assertStringNotContainsString('gateway-secret', json_encode($logs->toArray()));
    }

    public function test_route_headers_override_defaults_and_auth_overrides_both_case_insensitively(): void
    {
        $connector = $this->createConnector();
        $connector->update(['headers' => [self::HEADER => 'gateway-secret', 'X-Version' => 'default', 'authorization' => 'unused']]);
        $admin = app(ConnectorAdminService::class);
        $profile = $admin->createAuthProfile($connector, ['type' => 'bearer', 'credentials' => ['token' => 'auth-secret']]);
        $connector->update(['default_auth_profile_id' => $profile->id]);
        $route = $this->createRoute($connector, [
            ['name' => 'x-version', 'location' => 'header', 'source' => 'fixed', 'type' => 'string', 'value' => 'route'],
            ['name' => 'AUTHORIZATION', 'location' => 'header', 'source' => 'fixed', 'type' => 'string', 'value' => 'unused-route'],
        ]);

        app(ApiToolExecutor::class)->execute($route, []);
        Http::assertSent(fn (Request $request): bool => $request->header('X-Version') === ['route']
            && $request->header('Authorization') === ['Bearer auth-secret']
            && $request->header(self::HEADER) === ['gateway-secret']);
    }

    public function test_modal_test_endpoints_send_the_cloudflare_header_alongside_api_auth(): void
    {
        config()->set('connector-api.llm_assist.enabled', false);
        $connector = $this->createConnector();
        $profile = app(ConnectorAdminService::class)->createAuthProfile($connector, [
            'type' => 'bearer', 'credentials' => ['token' => 'auth-secret'],
        ]);
        $connector->update(['default_auth_profile_id' => $profile->id]);
        $route = $this->createRoute($connector);

        // "Testa chiamata" tests the current config without saving it first.
        $this->postJson('/api/admin/api-connectors/'.$connector->id.'/routes/test-config', [
            'config' => [
                'request' => ['http_method' => 'GET', 'url' => 'https://api.example.com/preview', 'params' => []],
            ],
            'example_args' => [],
        ])->assertOk()->assertJsonPath('test.ok', true);

        // Saving the modal also tests the persisted route through a separate endpoint.
        $this->postJson('/api/admin/api-connectors/routes/'.$route->id.'/test', [
            'example_args' => [],
        ])->assertOk()->assertJsonPath('test.ok', true);

        Http::assertSentCount(2);
        $this->assertSame(
            ['https://api.example.com/preview', 'https://api.example.com/orders'],
            Http::recorded()->map(fn (array $record): string => $record[0]->url())->all(),
        );
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->hasHeader(self::HEADER, 'gateway-secret'));
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer auth-secret'));
        }
    }

    public function test_removing_the_optional_header_takes_effect_on_the_next_request(): void
    {
        $connector = $this->createConnector();
        $route = $this->createRoute($connector);
        $this->patchJson('/api/admin/api-connectors/'.$connector->id, ['headers' => ['X-Tenant' => '42']])->assertOk();

        app(ApiToolExecutor::class)->execute($route->fresh(), []);
        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader(self::HEADER)
            && $request->hasHeader('X-Tenant', '42'));
    }

    public function test_headers_are_scoped_to_the_connection_and_do_not_weaken_the_url_guard(): void
    {
        $first = $this->createConnector();
        $second = $this->createConnector('second-secret');
        app(ApiToolExecutor::class)->execute($this->createRoute($first), []);
        app(ApiToolExecutor::class)->execute($this->createRoute($second), []);
        $requests = Http::recorded();
        $this->assertTrue($requests[0][0]->hasHeader(self::HEADER, 'gateway-secret'));
        $this->assertTrue($requests[1][0]->hasHeader(self::HEADER, 'second-secret'));

        $blocked = $this->createRoute($first);
        $blocked->update(['url' => 'https://169.254.169.254/latest/meta-data']);
        $this->assertArrayHasKey('error', app(ApiToolExecutor::class)->execute($blocked, []));
        Http::assertSentCount(2);
    }

    private function createConnector(string $token = 'gateway-secret'): ApiConnector
    {
        $response = $this->postJson('/api/admin/api-connectors', [
            'name' => 'ERP', 'base_url' => 'https://api.example.com',
            'headers' => [self::HEADER => $token], 'is_active' => true,
        ])->assertCreated();

        return ApiConnector::findOrFail($response->json('data.id'));
    }

    private function createRoute(ApiConnector $connector, array $parameters = []): ApiRoute
    {
        $route = app(ConnectorAdminService::class)->createRoute($connector, [
            'name' => 'Orders', 'slug' => 'orders_'.bin2hex(random_bytes(3)),
            'http_method' => 'GET', 'url' => 'https://api.example.com/orders',
            'mode' => 'tool', 'parameters' => $parameters,
        ]);
        $route->update(['status' => 'active', 'tool_definition' => [
            'name' => $route->slug, 'description' => 'Orders', 'input_schema' => ['type' => 'object'],
        ]]);

        return $route->fresh()->load('parameters');
    }
}
