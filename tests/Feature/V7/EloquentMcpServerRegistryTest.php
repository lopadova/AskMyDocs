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
 *  - Tenant scoping is strict — a null tenant means platform-global
 *    only, never "all tenants".
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
        $this->creator = User::create([
            'name' => 'Registry test',
            'email' => 'registry-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
            'tenant_id' => 'default',
        ]);
    }

    public function test_for_tenant_returns_only_active_servers_for_the_tenant(): void
    {
        $this->makeServer(['name' => 'Acme Alpha', 'tenant_id' => 'acme']);
        $this->makeServer(['name' => 'Globex Alpha', 'tenant_id' => 'globex']);
        // Disabled + pending rows must NOT leak.
        $this->makeServer(['name' => 'Disabled', 'tenant_id' => 'acme', 'status' => McpServer::STATUS_DISABLED]);
        $this->makeServer(['name' => 'Pending', 'tenant_id' => 'acme', 'status' => McpServer::STATUS_PENDING]);

        $registry = new EloquentMcpServerRegistry();
        $names = array_map(static fn($s): string => $s->name(), $registry->forTenant('acme'));

        $this->assertSame(['Acme Alpha'], $names);
    }

    public function test_null_tenant_is_platform_global_and_does_not_leak_other_tenants(): void
    {
        $this->makeServer(['name' => 'Public', 'tenant_id' => 'public']);
        $this->makeServer(['name' => 'Acme', 'tenant_id' => 'acme']);

        $registry = new EloquentMcpServerRegistry();
        // Per the package contract: `null` means "no tenant filter
        // applied at the registry level"; the authorizer is the
        // final gate. So `forTenant(null)` returns every active row.
        $names = array_map(static fn($s): string => $s->name(), $registry->forTenant(null));

        $this->assertEqualsCanonicalizing(['Acme', 'Public'], $names);
    }

    public function test_find_returns_adapter_for_active_row(): void
    {
        $server = $this->makeServer(['name' => 'Findable', 'tenant_id' => 'acme']);
        $registry = new EloquentMcpServerRegistry();

        $hit = $registry->find((string) $server->id);

        $this->assertNotNull($hit);
        $this->assertSame((string) $server->id, $hit->id());
        $this->assertSame('Findable', $hit->name());
    }

    public function test_find_returns_null_for_disabled_rows(): void
    {
        $disabled = $this->makeServer(['status' => McpServer::STATUS_DISABLED]);
        $registry = new EloquentMcpServerRegistry();

        $this->assertNull($registry->find((string) $disabled->id));
    }

    public function test_find_returns_null_for_non_numeric_id_without_crashing(): void
    {
        // A package caller passing a UUID-style id from a different
        // implementation must NOT cause an SQL error here. The
        // adapter rejects anything that isn't an int-string.
        $registry = new EloquentMcpServerRegistry();

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
