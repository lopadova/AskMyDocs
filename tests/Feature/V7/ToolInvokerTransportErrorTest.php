<?php

declare(strict_types=1);

namespace Tests\Feature\V7;

use App\Mcp\Client\ToolInvoker;
use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Tests\Support\Mcp\StubMcpTransport;
use Tests\TestCase;

/**
 * v7.0/W6.3.B — every `McpTransportException` raised inside the
 * native-transport call path classifies into one of two audit
 * statuses, depending on whether the underlying failure message
 * carries a timeout marker:
 *
 *   - `STATUS_TIMEOUT` — the legacy bucket for cURL-style
 *     "Operation timed out" / SSE keep-alive miss / stdio read
 *     timeout. Existing dashboards + alerting rules continue to
 *     fire here.
 *   - `STATUS_TRANSPORT_ERROR` — every NON-timeout transport
 *     failure (refused connection, malformed JSON-RPC envelope,
 *     upstream protocol violation). Surfaces a distinct pill in
 *     the admin audit view and survives the v7 schema widening.
 */
final class ToolInvokerTransportErrorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private McpServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');

        $this->user = User::create([
            'name' => 'Tool Invoker Test',
            'email' => 'invoker-'.uniqid().'@test.local',
            'password' => Hash::make('secret123'),
        ]);
        $this->server = McpServer::create([
            'tenant_id' => 'default',
            'name' => 'transport-test',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://example.test/mcp',
            'status' => McpServer::STATUS_ACTIVE,
            'enabled_tools_json' => ['*'],
            'created_by' => $this->user->id,
        ]);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_timeout_marker_in_exception_message_classifies_as_timeout(): void
    {
        $this->scriptTransportException('Operation timed out after 30000 ms');

        $this->expectException(\RuntimeException::class);

        try {
            (new ToolInvoker())->invoke(
                user: $this->user,
                server: $this->server,
                toolName: 'search',
                toolInput: ['q' => 'hello'],
            );
        } finally {
            $row = McpToolCallAudit::query()->latest('id')->first();
            $this->assertNotNull($row);
            $this->assertSame(McpToolCallAudit::STATUS_TIMEOUT, $row->status);
        }
    }

    public function test_non_timeout_transport_failure_classifies_as_transport_error(): void
    {
        $this->scriptTransportException('Connection refused: cURL error 7');

        $this->expectException(\RuntimeException::class);

        try {
            (new ToolInvoker())->invoke(
                user: $this->user,
                server: $this->server,
                toolName: 'search',
                toolInput: ['q' => 'hello'],
            );
        } finally {
            $row = McpToolCallAudit::query()->latest('id')->first();
            $this->assertNotNull($row);
            $this->assertSame(McpToolCallAudit::STATUS_TRANSPORT_ERROR, $row->status);
        }
    }

    public function test_protocol_violation_classifies_as_transport_error(): void
    {
        // A malformed JSON-RPC envelope arriving at the package's
        // client wraps in `McpTransportException` with a message like
        // "JSON-RPC method not found" — no timeout marker, so the
        // host audit row uses `transport_error` (the new dedicated
        // status), NOT `timeout`.
        $this->scriptTransportException('JSON-RPC method not found: tools/call');

        $this->expectException(\RuntimeException::class);

        try {
            (new ToolInvoker())->invoke(
                user: $this->user,
                server: $this->server,
                toolName: 'search',
                toolInput: [],
            );
        } finally {
            $row = McpToolCallAudit::query()->latest('id')->first();
            $this->assertNotNull($row);
            $this->assertSame(McpToolCallAudit::STATUS_TRANSPORT_ERROR, $row->status);
        }
    }

    private function scriptTransportException(string $message): void
    {
        McpClient::useTransportResolver(function (McpServerContract $s) use ($message): McpTransportContract {
            return new class($message) implements McpTransportContract {
                public function __construct(private readonly string $message) {}
                public function request(\Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage $request): \Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage
                {
                    throw new McpTransportException($this->message);
                }
                public function notify(\Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage $notification): void {}
                public function isHealthy(): bool { return false; }
            };
        });
    }
}
