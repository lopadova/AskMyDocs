<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use App\Support\TenantContext;
use Database\Seeders\CaseStudyUsersSeeder;
use Database\Seeders\LocalIntegrationConnectorsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Exercises the real PHP MCP client against the real local Node server. */
final class LocalIntegrationMcpInteropTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $touchedEnvironment = [];

    private ?Process $server = null;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        McpClient::useTransportResolver(null);
        $this->port = $this->freeLoopbackPort();
        $this->setEnvironment('APP_ENV', 'local');
        $this->setEnvironment(LocalIntegrationFixtureEnvironment::FLAG, 'true');
        $this->setEnvironment('LOCAL_INTEGRATIONS_MCP_BASE_URL', "http://127.0.0.1:{$this->port}/mcp");
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.http.internal_endpoint_allowlist', ['127.0.0.1']);
        app(TenantContext::class)->set('default');
        $this->startNodeServer();
        $this->seed(RbacSeeder::class);
        $this->seed(CaseStudyUsersSeeder::class);
    }

    protected function tearDown(): void
    {
        if ($this->server?->isRunning()) {
            $this->server->stop(3);
        }
        foreach ($this->touchedEnvironment as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        McpClient::useTransportResolver(null);

        parent::tearDown();
    }

    public function test_php_connector_discovers_the_real_scoped_node_mcp_server(): void
    {
        app(LocalIntegrationConnectorsSeeder::class)->run();

        foreach (CaseStudyUsersSeeder::companyKeys() as $companyKey) {
            $connection = McpConnection::withoutGlobalScopes()
                ->where('tenant_id', $companyKey)
                ->with('server')
                ->sole();
            $this->assertSame("http://127.0.0.1:{$this->port}/mcp/{$companyKey}", $connection->server->endpoint);
            $this->assertSame('active', $connection->status);
            $this->assertSame(
                [
                    'get_company_context',
                    'get_operational_record',
                    'list_operational_records',
                    'search_operational_records',
                ],
                McpConnectionTool::withoutGlobalScopes()
                    ->where('mcp_connector_connection_id', $connection->id)
                    ->orderBy('remote_name')
                    ->pluck('remote_name')
                    ->all(),
                json_encode($connection->fresh()->error_json, JSON_THROW_ON_ERROR),
            );
        }
    }

    private function startNodeServer(): void
    {
        $this->server = new Process(
            ['node', $this->projectRoot().'/dev/local-integrations/mcp-server.mjs'],
            $this->projectRoot(),
            ['LOCAL_INTEGRATIONS_MCP_PORT' => (string) $this->port],
        );
        $this->server->start();

        $healthUrl = "http://127.0.0.1:{$this->port}/health";
        for ($attempt = 0; $attempt < 30; $attempt++) {
            if ($this->server->isRunning() && @file_get_contents($healthUrl) !== false) {
                return;
            }
            usleep(100_000);
        }

        $output = trim($this->server->getErrorOutput()."\n".$this->server->getOutput());
        $this->fail('The local Node MCP fixture did not become healthy. '.$output);
    }

    private function freeLoopbackPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertNotFalse($socket, $errorMessage);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->assertIsString($name);

        return (int) substr(strrchr($name, ':'), 1);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function setEnvironment(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        $this->touchedEnvironment[] = $key;
    }
}
