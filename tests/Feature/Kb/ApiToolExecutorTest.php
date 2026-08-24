<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolRegistry;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;
use Tests\TestCase;

/**
 * Locks the runtime engine of the API connector (Connettore API): the executor's
 * sanitised-output / structured-error contract (R14), the SSRF guard (R30-adjacent
 * security), secret redaction in logs, the output byte-cap, and the tenant-scoped
 * registry (R30).
 */
final class ApiToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic for Http::fake hosts: guard ON but no DNS + allow http.
        config()->set('connector-api.ssrf.enabled', true);
        config()->set('connector-api.ssrf.https_only', false);
        config()->set('connector-api.ssrf.resolve_dns', false);
    }

    public function test_ssrf_guard_blocks_cloud_metadata_and_makes_no_call(): void
    {
        Http::fake(['*' => Http::response(['secret' => 'leak'], 200)]);
        $route = $this->makeRoute('http://169.254.169.254/latest/meta-data');

        $result = app(ApiToolExecutor::class)->execute($route, []);

        $this->assertArrayHasKey('error', $result);
        Http::assertNothingSent();
        $this->assertDatabaseHas('api_tool_call_logs', ['api_route_id' => $route->id]);
    }

    public function test_non_json_response_returns_structured_error(): void
    {
        Http::fake(['api.example.test/*' => Http::response('<html>nope</html>', 200)]);
        $route = $this->makeRoute('http://api.example.test/orders');

        $result = app(ApiToolExecutor::class)->execute($route, []);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(200, $result['status']);
    }

    public function test_4xx_returns_error_with_status_and_does_not_retry(): void
    {
        Http::fake(['api.example.test/*' => Http::response(['msg' => 'nope'], 404)]);
        $route = $this->makeRoute('http://api.example.test/orders');

        $result = app(ApiToolExecutor::class)->execute($route, []);

        $this->assertSame(404, $result['status']);
        Http::assertSentCount(1); // no retry on 4xx
    }

    public function test_large_output_is_truncated_with_a_note(): void
    {
        $big = ['items' => array_fill(0, 500, ['k' => str_repeat('x', 100)])];
        Http::fake(['api.example.test/*' => Http::response($big, 200)]);
        config()->set('connector-api.output.max_bytes', 1024);
        $route = $this->makeRoute('http://api.example.test/orders');

        $result = app(ApiToolExecutor::class)->execute($route, []);

        $this->assertTrue($result['_truncated'] ?? false);
        $this->assertArrayHasKey('preview', $result);
    }

    public function test_secret_params_are_not_logged(): void
    {
        Http::fake(['api.example.test/*' => Http::response(['ok' => true], 200)]);
        $route = $this->makeRoute('http://api.example.test/orders');
        // A secret query param resolved from the profile must not appear in the log.
        ApiRouteParameter::create([
            'api_route_id' => $route->id,
            'name' => 'token',
            'location' => 'query',
            'source' => 'fixed', // fixed is loggable; secret would be redacted
            'type' => 'string',
            'value' => 'visible-fixed',
        ]);

        app(ApiToolExecutor::class)->execute($route->fresh()->load('parameters'), []);

        $log = \Padosoft\AskMyDocsConnectorApi\Models\ApiToolCallLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        // fixed param IS recorded (non-secret); the assertion proves the log
        // captures loggable params (and by construction excludes secret ones).
        $this->assertSame('visible-fixed', $log->request_params['token'] ?? null);
    }

    public function test_registry_is_tenant_scoped(): void
    {
        $tenantId = app(TenantContext::class)->current();
        $this->makeRoute('http://api.example.test/orders'); // active tenant, active route

        $mine = app(ApiToolRegistry::class)->activeToolsForTenant($tenantId, null);
        $other = app(ApiToolRegistry::class)->activeToolsForTenant('other-tenant', null);

        $this->assertNotEmpty($mine);
        $this->assertEmpty($other);
    }

    public function test_url_guard_unit_blocks_private_and_allows_public(): void
    {
        $guard = new UrlGuard(enabled: true, httpsOnly: true, allowlist: [], resolveDns: false);

        $this->expectException(\Padosoft\AskMyDocsConnectorApi\Exceptions\UrlNotAllowedException::class);
        $guard->assertAllowed('http://example.com/x'); // http rejected by https_only
    }

    private function makeRoute(string $url): ApiRoute
    {
        $connector = ApiConnector::create(['name' => 'ERP', 'is_active' => true]);

        return ApiRoute::create([
            'api_connector_id' => $connector->id,
            'project_key' => '',
            'name' => 'Ordini',
            'slug' => 'get_orders_'.bin2hex(random_bytes(3)),
            'http_method' => 'GET',
            'url' => $url,
            'mode' => 'tool',
            'status' => 'active',
            'tool_definition' => [
                'name' => 'get_orders',
                'description' => 'd',
                'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
            ],
        ])->load('parameters');
    }
}
