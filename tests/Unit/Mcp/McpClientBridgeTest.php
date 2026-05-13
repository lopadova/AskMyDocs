<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\Client\McpClientBridge;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class McpClientBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mcp.sidecar.base_url', 'http://127.0.0.1:3535');
        config()->set('mcp.sidecar.health_endpoint', '/healthz');
        config()->set('mcp.sidecar.timeout_ms', 2500);
    }

    public function test_is_healthy_returns_true_when_sidecar_health_is_ok(): void
    {
        Http::fake([
            'http://127.0.0.1:3535/healthz' => Http::response('ok', 200),
        ]);

        $bridge = new McpClientBridge();
        $this->assertTrue($bridge->isHealthy());
    }

    public function test_is_healthy_returns_false_when_sidecar_health_is_not_ok(): void
    {
        Http::fake([
            'http://127.0.0.1:3535/healthz' => Http::response('down', 500),
        ]);

        $bridge = new McpClientBridge();
        $this->assertFalse($bridge->isHealthy());
    }

    public function test_invoke_tool_returns_decoded_payload(): void
    {
        $payload = ['ok' => true, 'result' => ['count' => 2]];
        Http::fake([
            'http://127.0.0.1:3535/invoke-tool' => Http::response($payload, 200),
        ]);

        $bridge = new McpClientBridge();
        $result = $bridge->invokeTool([
            'server_id' => 12,
            'server_name' => 'kb',
            'tool_name' => 'search_docs',
            'input' => ['q' => 'x'],
        ]);

        $this->assertSame($payload, $result);
    }

    public function test_handshake_returns_decoded_payload(): void
    {
        $payload = ['status' => 'ok', 'tools' => ['search_docs']];
        Http::fake([
            'http://127.0.0.1:3535/handshake' => Http::response($payload, 200),
        ]);

        $bridge = new McpClientBridge();
        $result = $bridge->handshake([
            'server_id' => 12,
            'name' => 'kb',
            'transport' => 'http',
            'endpoint' => 'http://127.0.0.1:3535',
        ]);

        $this->assertSame($payload, $result);
    }

    public function test_invoke_tool_throws_runtime_exception_on_failure_status(): void
    {
        Http::fake([
            'http://127.0.0.1:3535/invoke-tool' => Http::response('boom', 500),
        ]);

        $bridge = new McpClientBridge();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP sidecar request failed');
        $bridge->invokeTool(['server_id' => 12]);
    }
}

