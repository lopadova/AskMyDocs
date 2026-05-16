<?php

declare(strict_types=1);

namespace Tests\Feature\V7;

use App\Mcp\Adapters\EloquentMcpServerRegistry;
use App\Models\McpServer;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v7.0/W6.3 — host registry adapter on top of the `mcp_servers`
 * Eloquent table. Behaviour we lock in:
 *
 *  - Only ACTIVE servers surface; disabled / pending / errored stay
 *    invisible to the orchestrator.
 *  - Tenant scoping is strict. The contract's `$tenantId` hint
 *    wins when provided; a NULL hint falls back to the host's
 *    `TenantContext::current()` (never widens to all tenants —
 *    that would be cross-tenant data leakage).
 *  - `find($id)` is also tenant-scoped via `TenantContext` so
 *    duplicate ids across tenants surface the row owned by the
 *    active tenant.
 *  - `find($id)` accepts the package's string id (the host's int
 *    PK cast to string) and rejects non-numeric input rather than
 *    crashing the query.
 */
final class EloquentMcpServerRegistryTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        app(TenantContext::class)->set('default');
        // `users` isn't tenant-scoped — `tenant_id` is not in
        // User::$fillable and the column doesn't exist on the
        // table. Drop it from the fixture so the no-op doesn't
        // mislead readers about tenant scoping.
        $this->creator = User::create([
            'name' => 'Registry test',
            'email' => 'registry-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    public function test_for_tenant_returns_only_active_servers_for_the_tenant(): void
    {
        $this->makeServer(['name' => 'Acme Alpha', 'tenant_id' => 'acme']);
        $this->makeServer(['name' => 'Globex Alpha', 'tenant_id' => 'globex']);
        // Disabled + pending rows must NOT leak.
        $this->makeServer(['name' => 'Disabled', 'tenant_id' => 'acme', 'status' => McpServer::STATUS_DISABLED]);
        $this->makeServer(['name' => 'Pending', 'tenant_id' => 'acme', 'status' => McpServer::STATUS_PENDING]);

        $registry = new EloquentMcpServerRegistry(app(TenantContext::class));
        $names = array_map(static fn($s): string => $s->name(), $registry->forTenant('acme'));

        $this->assertSame(['Acme Alpha'], $names);
    }

    public function test_null_tenant_falls_back_to_tenant_context_no_cross_tenant_leak(): void
    {
        // **R30 regression**: a previous shape made `forTenant(null)`
        // return EVERY active row, which is cross-tenant data
        // leakage. The adapter now resolves the active tenant from
        // the host's `TenantContext` so a missing hint cannot widen
        // the query past the current request's tenant.
        $this->makeServer(['name' => 'DefaultOne', 'tenant_id' => 'default']);
        $this->makeServer(['name' => 'AcmeOnly', 'tenant_id' => 'acme']);
        $this->makeServer(['name' => 'GlobexOnly', 'tenant_id' => 'globex']);

        // TenantContext is set to 'default' in setUp() — null tenant
        // hint MUST surface only 'default' rows.
        $registry = new EloquentMcpServerRegistry(app(TenantContext::class));
        $names = array_map(static fn($s): string => $s->name(), $registry->forTenant(null));

        $this->assertSame(['DefaultOne'], $names);
    }

    public function test_null_tenant_follows_tenant_context_when_it_changes(): void
    {
        $this->makeServer(['name' => 'DefaultOne', 'tenant_id' => 'default']);
        $this->makeServer(['name' => 'AcmeOnly', 'tenant_id' => 'acme']);

        $registry = new EloquentMcpServerRegistry(app(TenantContext::class));

        app(TenantContext::class)->set('acme');
        $names = array_map(static fn($s): string => $s->name(), $registry->forTenant(null));
        $this->assertSame(['AcmeOnly'], $names);
    }

    public function test_find_returns_adapter_for_active_row_in_active_tenant(): void
    {
        $server = $this->makeServer(['name' => 'Findable', 'tenant_id' => 'acme']);
        app(TenantContext::class)->set('acme');
        $registry = new EloquentMcpServerRegistry(app(TenantContext::class));

        $hit = $registry->find((string) $server->id);

        $this->assertNotNull($hit);
        $this->assertSame((string) $server->id, $hit->id());
        $this->assertSame('Findable', $hit->name());
    }

    public function test_find_returns_null_when_id_belongs_to_a_different_tenant(): void
    {
        // **R30 regression**: previously `find()` was unscoped, so a
        // duplicate id between tenants could surface the WRONG row.
        // The adapter now scopes by `TenantContext::current()` so
        // an id outside the active tenant returns null.
        $acmeServer = $this->makeServer(['name' => 'AcmeOnly', 'tenant_id' => 'acme']);

        // TenantContext stays at 'default' (set in setUp). Looking
        // up the acme row from a default-tenant request MUST miss.
        $registry = new EloquentMcpServerRegistry(app(TenantContext::class));
        $this->assertNull($registry->find((string) $acmeServer->id));
    }

    public function test_find_returns_null_for_disabled_rows(): void
    {
        $disabled = $this->makeServer(['status' => McpServer::STATUS_DISABLED]);
        $registry = new EloquentMcpServerRegistry(app(TenantContext::class));

        $this->assertNull($registry->find((string) $disabled->id));
    }

    public function test_find_returns_null_for_non_numeric_id_without_crashing(): void
    {
        // A package caller passing a UUID-style id from a different
        // implementation must NOT cause an SQL error here. The
        // adapter rejects anything that isn't an int-string.
        $registry = new EloquentMcpServerRegistry(app(TenantContext::class));

        $this->assertNull($registry->find('not-a-number'));
        $this->assertNull($registry->find('11abc'));
        $this->assertNull($registry->find(''));
    }

    /** @param  array<string,mixed>  $overrides */
    private function makeServer(array $overrides = []): McpServer
    {
        return McpServer::create(array_merge([
            'tenant_id' => 'default',
            'name' => 'Test '.uniqid('', true),
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://example.test',
            'auth_config_encrypted' => null,
            'enabled_tools_json' => [],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $this->creator->id,
        ], $overrides));
    }
}
