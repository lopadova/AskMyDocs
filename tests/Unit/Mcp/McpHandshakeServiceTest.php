<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\Client\McpClientBridge;
use App\Mcp\Client\McpHandshakeService;
use App\Models\McpServer;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class McpHandshakeServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');

        $this->creator = User::create([
            'name' => 'Handshake Creator',
            'email' => 'mcp-handshake-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    public function test_refresh_updates_server_and_returns_handshake_payload(): void
    {
        $server = McpServer::create([
            'tenant_id' => 'default',
            'name' => 'kb-server',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://127.0.0.1:3535',
            'enabled_tools_json' => ['*'],
            'status' => McpServer::STATUS_PENDING,
            'created_by' => $this->creator->id,
        ]);

        $response = ['status' => 'ok', 'tools' => ['search_docs', 'graph']];

        Http::fake([
            'http://127.0.0.1:3535/handshake' => Http::response($response, 200),
        ]);

        $bridge = new McpClientBridge();
        $service = new McpHandshakeService($bridge);
        $result = $service->refresh($server);

        $server->refresh();
        $this->assertSame($response, $result);
        $this->assertSame(McpServer::STATUS_ACTIVE, $server->status);
        $this->assertNotNull($server->last_handshake_at);
        $this->assertSame($response, $server->handshake_response_json);
    }
}
