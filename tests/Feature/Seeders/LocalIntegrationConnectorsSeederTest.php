<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use App\Support\TenantContext;
use Database\Seeders\CaseStudyUsersSeeder;
use Database\Seeders\LocalIntegrationConnectorsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Tests\Support\Mcp\StubMcpTransport;
use Tests\TestCase;

final class LocalIntegrationConnectorsSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $touchedEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnvironment('APP_ENV', 'local');
        $this->setEnvironment(LocalIntegrationFixtureEnvironment::FLAG, 'true');
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.http.internal_endpoint_allowlist', ['127.0.0.1']);
        app(TenantContext::class)->set('default');
        $this->seed(RbacSeeder::class);
        $this->seed(CaseStudyUsersSeeder::class);

        McpClient::useTransportResolver(static function (): McpTransportContract {
            $transport = new StubMcpTransport;
            $transport->responses['server/discover'] = [
                'protocolVersion' => '2026-07-28',
                'capabilities' => ['tools' => []],
                'serverInfo' => ['name' => 'local-fixture'],
            ];
            $transport->responses['tools/list'] = ['tools' => [
                [
                    'name' => 'search_shipments',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true],
                ],
                [
                    'name' => 'search_orders',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true],
                ],
            ]];

            return $transport;
        });
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        foreach ($this->touchedEnvironment as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function test_creates_idempotent_company_scoped_api_and_mcp_connectors(): void
    {
        $seeder = app(LocalIntegrationConnectorsSeeder::class);
        $seeder->run();
        $seeder->run();

        foreach (CaseStudyUsersSeeder::companyKeys() as $companyKey) {
            $connector = ApiConnector::withoutGlobalScopes()
                ->where('tenant_id', $companyKey)
                ->where('project_key', $companyKey)
                ->where('name', 'Fixture operativo locale')
                ->sole();
            $this->assertSame('http://127.0.0.1:4310', $connector->base_url);

            $routes = ApiRoute::withoutGlobalScopes()
                ->where('tenant_id', $companyKey)
                ->orderBy('slug')
                ->get();
            $this->assertCount(5, $routes);
            $this->assertSame(
                [
                    'fixture_get_claim',
                    'fixture_get_inventory',
                    'fixture_list_claims',
                    'fixture_list_inventory',
                    'fixture_recent_orders',
                ],
                $routes->pluck('slug')->all(),
            );
            $this->assertTrue($routes->every(
                fn (ApiRoute $route): bool => str_starts_with(
                    $route->url,
                    "http://127.0.0.1:4310/v1/companies/{$companyKey}/",
                ),
            ));

            $connection = McpConnection::withoutGlobalScopes()
                ->where('tenant_id', $companyKey)
                ->where('project_key', $companyKey)
                ->where('label', 'Fixture MCP operativo locale')
                ->with('server')
                ->sole();
            $this->assertSame("http://127.0.0.1:4311/mcp/{$companyKey}", $connection->server->endpoint);
            $this->assertSame('active', $connection->status);
            $this->assertSame(2, McpConnectionTool::withoutGlobalScopes()
                ->where('tenant_id', $companyKey)
                ->where('mcp_connector_connection_id', $connection->id)
                ->where('enabled', true)
                ->where('read_only', true)
                ->count());
        }

        $this->assertSame(3, ApiConnector::withoutGlobalScopes()->count());
        $this->assertSame(15, ApiRoute::withoutGlobalScopes()->count());
        $this->assertSame(3, McpConnection::withoutGlobalScopes()->count());
    }

    private function setEnvironment(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        $this->touchedEnvironment[] = $key;
    }
}
