<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\Client\McpClientBridge;
use App\Mcp\Client\ToolInvoker;
use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ToolInvokerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');

        $this->user = User::create([
            'name' => 'Invoker User',
            'email' => 'mcp-invoker-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    public function test_invoke_persists_ok_audit_and_returns_result(): void
    {
        $server = $this->createServer();

        Http::fake([
            'http://127.0.0.1:3535/invoke-tool' => Http::response(['data' => ['items' => 1]], 200),
        ]);

        $bridge = new McpClientBridge();
        $service = new ToolInvoker($bridge);
        $result = $service->invoke(
            user: $this->user,
            server: $server,
            toolName: 'search_docs',
            toolInput: ['query' => 'abc'],
            context: [],
        );

        $this->assertSame(['data' => ['items' => 1]], $result);
        $row = McpToolCallAudit::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('ok', $row->status);
        $this->assertSame('search_docs', $row->tool_name);
        $this->assertNull($row->conversation_id);
        $this->assertNull($row->message_id);
    }

    public function test_invoke_timeout_throws_and_persists_timeout_status(): void
    {
        $server = $this->createServer();

        Http::fake([
            'http://127.0.0.1:3535/invoke-tool' => Http::response('timeout', 504),
        ]);

        $bridge = new McpClientBridge();
        $service = new ToolInvoker($bridge);

        try {
            $service->invoke($this->user, $server, 'search_docs', ['query' => 'abc']);
            $this->fail('Expected runtime exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('MCP tool invocation failed.', $e->getMessage());
        }

        $row = McpToolCallAudit::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('timeout', $row->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createServer(array $overrides = []): McpServer
    {
        return McpServer::create(array_merge([
            'tenant_id' => app(TenantContext::class)->current(),
            'name' => 'server-'.uniqid(),
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://127.0.0.1:3535',
            'enabled_tools_json' => ['*'],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $this->user->id,
        ], $overrides));
    }
}
