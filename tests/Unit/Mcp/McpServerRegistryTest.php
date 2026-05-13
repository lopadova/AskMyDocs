<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\Client\Registry\McpServerRegistry;
use App\Models\McpServer;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v5.0/W1 — tenant-scoped MCP server registry unit contract.
 */
final class McpServerRegistryTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');

        $this->creator = User::create([
            'name' => 'Mcp Server Registry Creator '.uniqid('', true),
            'email' => 'mcp-registry-'.uniqid('', true).'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    public function test_active_servers_for_tenant_returns_only_active_rows_in_current_tenant(): void
    {
        $tenantServer = $this->createServer([
            'tenant_id' => 'default',
            'name' => 'tenant-default-active',
            'status' => McpServer::STATUS_ACTIVE,
        ]);
        $this->createServer([
            'tenant_id' => 'tenant-x',
            'name' => 'tenant-x-active',
            'status' => McpServer::STATUS_ACTIVE,
        ]);
        $this->createServer([
            'tenant_id' => 'default',
            'name' => 'tenant-default-disabled',
            'status' => McpServer::STATUS_DISABLED,
        ]);

        $registry = new McpServerRegistry(app(TenantContext::class));
        $rows = $registry->activeServersForTenant();

        $this->assertCount(1, $rows);
        $this->assertSame($tenantServer->id, $rows->first()->id);
    }

    public function test_enabled_tools_for_server_returns_array_or_empty_when_not_found(): void
    {
        $server = $this->createServer([
            'enabled_tools_json' => ['search_docs', 'graph'],
        ]);
        $registry = new McpServerRegistry(app(TenantContext::class));

        $this->assertSame(['search_docs', 'graph'], $registry->enabledToolsForServer($server->id));
        $this->assertSame([], $registry->enabledToolsForServer(9999));
    }

    public function test_for_tenant_filters_by_id_when_requested(): void
    {
        $server = $this->createServer([
            'name' => 'filterable-server',
        ]);
        $this->createServer([
            'tenant_id' => 'tenant-x',
            'name' => 'other-tenant-server',
        ]);

        $registry = new McpServerRegistry(app(TenantContext::class));

        $found = $registry->forTenant($server->id);
        $this->assertCount(1, $found);
        $this->assertSame($server->id, $found->first()->id);

        $other = $registry->forTenant($server->id + 1);
        $this->assertCount(0, $other);
    }

    public function test_find_server_and_resolve_for_tenant(): void
    {
        $server = $this->createServer([
            'name' => 'resolvable',
        ]);
        $registry = new McpServerRegistry(app(TenantContext::class));

        $found = $registry->findServer($server->id);
        $this->assertNotNull($found);
        $this->assertSame($server->id, $found->id);
        $this->assertSame($server->id, $registry->resolveForTenant($server->id)->id);
    }

    public function test_has_server_matches_tenant_and_id_presence(): void
    {
        $server = $this->createServer([
            'name' => 'exists',
        ]);
        $registry = new McpServerRegistry(app(TenantContext::class));

        $this->assertTrue($registry->hasServer($server->id));
        $this->assertFalse($registry->hasServer($server->id + 1000));
    }

    public function test_active_tools_by_tenant_returns_map_for_active_servers_only(): void
    {
        $server = $this->createServer([
            'name' => 'default-with-tools',
            'status' => McpServer::STATUS_ACTIVE,
            'enabled_tools_json' => ['search_docs'],
        ]);
        $this->createServer([
            'tenant_id' => 'default',
            'name' => 'default-disabled',
            'status' => McpServer::STATUS_DISABLED,
            'enabled_tools_json' => ['graph'],
        ]);
        $this->createServer([
            'tenant_id' => 'tenant-x',
            'name' => 'other-tenant',
            'status' => McpServer::STATUS_ACTIVE,
            'enabled_tools_json' => ['graph'],
        ]);

        $registry = new McpServerRegistry(app(TenantContext::class));
        $map = $registry->activeToolsByTenant();

        $this->assertSame([
            $server->id => ['search_docs'],
        ], $map);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createServer(array $overrides = []): McpServer
    {
        $tenant = app(TenantContext::class)->current();
        return McpServer::create(array_merge([
            'tenant_id' => $tenant,
            'name' => 'server-'.uniqid(),
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://127.0.0.1:3535',
            'enabled_tools_json' => ['*'],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $this->creator->id,
        ], $overrides));
    }
}
