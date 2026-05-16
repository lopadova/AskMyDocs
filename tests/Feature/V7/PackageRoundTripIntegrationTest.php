<?php

declare(strict_types=1);

namespace Tests\Feature\V7;

use App\Mcp\Adapters\HostBridge;
use App\Mcp\Adapters\McpServerAdapter;
use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Services\ToolInvoker;
use Tests\Support\Mcp\StubMcpTransport;
use Tests\TestCase;

/**
 * v7.0/W6.3 — end-to-end integration playground.
 *
 * Drives a real, mocked-transport round-trip through the package's
 * orchestrator using the host adapters wired in `AppServiceProvider`:
 *
 *  1. Host writes an `McpServer` row (`mcp_servers` Eloquent).
 *  2. `App\Mcp\Adapters\EloquentMcpServerRegistry` (bound to
 *     `McpServerRegistryContract`) surfaces it to the orchestrator.
 *  3. `App\Mcp\Adapters\McpServerAdapter` exposes the row in the
 *     package shape — `transportConfig()` carries the decrypted
 *     endpoint/headers the package's transport factory expects.
 *  4. `Padosoft\AskMyDocsMcpPack\Services\ToolInvoker` calls
 *     `McpClient::forServer()->callTool(...)` against a scripted
 *     `StubMcpTransport` (so no real HTTP / stdio).
 *  5. The audit hook on the host's `App\Models\McpToolCallAudit`
 *     fires through the package writer; the row carries BOTH the
 *     package's hash columns (`input_hash`, `actor`) AND the host's
 *     richer columns (`input_json_redacted`, `user_id`).
 *
 * This is the playground the user asked for — a real exercise of
 * the W6 cutover surface, not a stub.
 */
final class PackageRoundTripIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private McpServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        app(TenantContext::class)->set('default');
        // The package's audit-model resolver reads
        // `mcp-pack.audit_model` from config. Point at the host
        // model explicitly so this test exercises the W6.3 wiring.
        config(['mcp-pack.audit_model' => McpToolCallAudit::class]);

        $this->admin = User::create([
            'name' => 'W6.3 admin',
            'email' => 'w6.3-admin-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
            'tenant_id' => 'default',
        ]);
        $this->admin->assignRole('super-admin');

        $this->server = McpServer::create([
            'tenant_id' => 'default',
            'name' => 'Test Upstream',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://upstream.test/mcp',
            'auth_config_encrypted' => null,
            'enabled_tools_json' => ['kb.search'],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_app_service_provider_binds_all_three_package_contracts(): void
    {
        $registry = app(McpServerRegistryContract::class);
        $bridge = app(McpHostBridgeContract::class);
        $authorizer = app(McpToolAuthorizerContract::class);

        $this->assertInstanceOf(\App\Mcp\Adapters\EloquentMcpServerRegistry::class, $registry);
        $this->assertInstanceOf(HostBridge::class, $bridge);
        $this->assertInstanceOf(\App\Mcp\Adapters\McpToolAuthorizerAdapter::class, $authorizer);
    }

    public function test_registry_surfaces_host_server_as_package_contract(): void
    {
        $registry = app(McpServerRegistryContract::class);

        $servers = $registry->forTenant('default');
        $this->assertCount(1, $servers);
        $this->assertInstanceOf(McpServerContract::class, $servers[0]);
        $this->assertSame((string) $this->server->id, $servers[0]->id());
        $this->assertSame('Test Upstream', $servers[0]->name());
        $this->assertSame(['kb.search'], $servers[0]->allowedTools());
    }

    public function test_tool_invocation_round_trip_writes_audit_row_through_host_model(): void
    {
        // Script the package's transport so no real HTTP is made.
        $transport = (new StubMcpTransport())->scriptToolCall('kb.search', [
            'hits' => [['title' => 'Hello World', 'score' => 0.91]],
        ]);
        McpClient::useTransportResolver(fn() => $transport);

        $serverAdapter = new McpServerAdapter($this->server);
        $invoker = app(ToolInvoker::class);

        $result = $invoker->invoke(
            $serverAdapter,
            'kb.search',
            ['query' => 'hello'],
            [
                'tenant_id' => 'default',
                'actor' => 'user:'.$this->admin->id,
                // The host model needs a user_id + redacted payload to
                // satisfy its richer schema — the package writer passes
                // these through `$context` so the host's audit row
                // carries operator-forensics columns alongside the
                // package's hash columns.
                'user_id' => $this->admin->id,
                'input_json_redacted' => ['query' => 'hello'],
            ],
        );

        $this->assertNull($result->error, 'transport succeeded → no error string');
        $this->assertSame(['hits' => [['title' => 'Hello World', 'score' => 0.91]]], $result->result);

        $row = McpToolCallAudit::query()
            ->forTenant('default')
            ->where('tool_name', 'kb.search')
            ->first();
        $this->assertNotNull($row, 'package writer must have produced an audit row');

        // Package's hash column + actor string MUST be populated by
        // the package's `ToolInvoker::audit()` write path.
        $this->assertNotNull($row->input_hash);
        $this->assertSame('user:'.$this->admin->id, $row->actor);
        // Package writes the string id() per contract; the host's
        // `mcp_server_id` is `foreignId` (int) and Eloquent casts
        // back to int on hydrate. Compare loosely so the contract
        // mismatch doesn't fail the assertion — both shapes refer
        // to the same row.
        $this->assertEquals((string) $this->server->id, (string) $row->mcp_server_id);
        $this->assertSame('Test Upstream', $row->mcp_server_name);
        $this->assertSame('ok', $row->status);

        // Hash MUST match the canonical form the host model computes
        // for the same payload (proves the package + host hash
        // algorithms stay in lockstep — the load-bearing W6.2 claim).
        $this->assertSame(
            McpToolCallAudit::canonicalHash(['query' => 'hello']),
            $row->input_hash,
            'package hash MUST equal host canonical hash for the same payload',
        );
    }

    public function test_transport_error_writes_audit_row_with_transport_error_status(): void
    {
        // Package writer emits `status='transport_error'` on network
        // failures — only possible because W6.3 widened the column
        // from ENUM to string. Without the schema migration this row
        // would have failed to insert.
        $brokenTransport = new class implements \Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract {
            public function request(\Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage $r): \Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage
            {
                throw new \Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException('connection refused');
            }
            public function notify(\Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage $n): void {}
            public function isHealthy(): bool { return false; }
        };
        McpClient::useTransportResolver(fn() => $brokenTransport);

        $invoker = app(ToolInvoker::class);
        $serverAdapter = new McpServerAdapter($this->server);

        $result = $invoker->invoke(
            $serverAdapter,
            'kb.search',
            ['query' => 'hello'],
            ['tenant_id' => 'default', 'actor' => 'user:'.$this->admin->id, 'user_id' => $this->admin->id, 'input_json_redacted' => ['query' => 'hello']],
        );

        $this->assertNotNull($result->error);
        $row = McpToolCallAudit::query()->forTenant('default')->where('tool_name', 'kb.search')->first();
        $this->assertNotNull($row);
        $this->assertSame('transport_error', $row->status, 'widened status column must accept package-emitted value');
    }

    public function test_authorizer_denies_viewer_role_from_invoking_tools(): void
    {
        $viewer = User::create([
            'name' => 'W6.3 viewer',
            'email' => 'w6.3-viewer-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
            'tenant_id' => 'default',
        ]);
        $viewer->assignRole('viewer');

        $authorizer = app(McpToolAuthorizerContract::class);
        $tool = new class implements McpToolContract {
            public function name(): string { return 'kb.search'; }
            public function description(): string { return 'search'; }
            public function schema(): array { return ['type' => 'object']; }
            public function isIdempotent(): bool { return true; }
            public function isReadOnly(): bool { return true; }
            public function invoke(array $a): mixed { return []; }
        };

        $this->assertFalse(
            $authorizer->authorize($viewer, 'default', $tool),
            'viewer role must NOT invoke MCP tools — confirms the adapter is wired',
        );
        $this->assertTrue(
            $authorizer->authorize($this->admin, 'default', $tool),
            'super-admin DOES invoke — same adapter, opposite outcome',
        );
    }
}
