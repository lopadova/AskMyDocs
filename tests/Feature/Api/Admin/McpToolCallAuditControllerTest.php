<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v5.0/W1 — MCP audit trail API (read-only, tenant-scoped).
 */
final class McpToolCallAuditControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        app(TenantContext::class)->set('default');
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/admin/mcp-tool-call-audit')->assertStatus(401);
    }

    public function test_non_authorized_user_is_forbidden(): void
    {
        $viewer = $this->makeViewer();

        $this->actingAs($viewer)
            ->getJson('/api/admin/mcp-tool-call-audit')
            ->assertStatus(403);
    }

    public function test_admin_can_read_own_tenant_audit_rows_with_default_ordering(): void
    {
        $admin = $this->makeAdmin();
        $server = $this->createServer($admin);

        // Newer row first in response due controller ORDER BY created_at DESC.
        $older = $this->createAuditRow($admin, $server, McpToolCallAudit::STATUS_OK, 'search_docs', Carbon::now()->subHours(2));
        $newer = $this->createAuditRow($admin, $server, McpToolCallAudit::STATUS_ERROR, 'graph_lookup', Carbon::now());

        $response = $this->actingAs($admin)->getJson('/api/admin/mcp-tool-call-audit');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        $this->assertSame($newer->id, $rows[0]['id']);
        $this->assertSame($older->id, $rows[1]['id']);
        $this->assertSame('search_docs', $rows[1]['tool_name']);
        $this->assertSame('graph_lookup', $rows[0]['tool_name']);
        $this->assertSame('error', $rows[0]['status']);
    }

    public function test_admin_can_filter_audit_rows_by_status_and_tool_and_server(): void
    {
        $admin = $this->makeAdmin();
        $serverA = $this->createServer($admin, ['name' => 'kb-server-a']);
        $serverB = $this->createServer($admin, ['name' => 'kb-server-b']);

        $rowA = $this->createAuditRow($admin, $serverA, McpToolCallAudit::STATUS_ERROR, 'search_docs');
        $this->createAuditRow($admin, $serverA, McpToolCallAudit::STATUS_OK, 'graph');
        $this->createAuditRow($admin, $serverB, McpToolCallAudit::STATUS_ERROR, 'graph');

        $response = $this->actingAs($admin)->getJson(sprintf(
            '/api/admin/mcp-tool-call-audit?status=%s&tool_name=%s&mcp_server_id=%s',
            McpToolCallAudit::STATUS_ERROR,
            'search_docs',
            $serverA->id,
        ));

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame((int) $rowA->id, (int) $rows[0]['id']);
        $this->assertSame('search_docs', $rows[0]['tool_name']);
    }

    public function test_admin_can_filter_by_date_range_and_limit(): void
    {
        $admin = $this->makeAdmin();
        $server = $this->createServer($admin);
        $old = Carbon::now()->subDays(5)->setTime(9, 0);
        $now = Carbon::now()->setTime(9, 0);
        $yesterday = Carbon::now()->subDay()->setTime(9, 0);

        $this->createAuditRow($admin, $server, McpToolCallAudit::STATUS_OK, 'search_docs', $old);
        $this->createAuditRow($admin, $server, McpToolCallAudit::STATUS_OK, 'search_docs', $yesterday);
        $recent = $this->createAuditRow($admin, $server, McpToolCallAudit::STATUS_OK, 'search_docs', $now);

        $response = $this->actingAs($admin)->getJson(sprintf(
            '/api/admin/mcp-tool-call-audit?from=%s&to=%s&limit=2',
            $yesterday->toDateString(),
            $now->toDateString(),
        ));

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame((int) $recent->id, (int) $rows[0]['id']);
    }

    public function test_super_admin_is_allowed(): void
    {
        $super = $this->makeSuperAdmin();
        $server = $this->createServer($super);
        $this->createAuditRow($super, $server, McpToolCallAudit::STATUS_ERROR, 'search_docs');

        $this->actingAs($super)->getJson('/api/admin/mcp-tool-call-audit')->assertOk();
    }

    public function test_cross_tenant_audit_rows_are_hidden(): void
    {
        $admin = $this->makeAdmin();
        $adminTenant = $this->createServer($admin);
        $otherTenant = $this->createServer($admin, ['tenant_id' => 'tenant-x']);

        $this->createAuditRow($admin, $adminTenant, McpToolCallAudit::STATUS_OK, 'search_docs');
        $otherAudit = $this->createAuditRow($admin, $otherTenant, McpToolCallAudit::STATUS_OK, 'search_docs', null, 'tenant-x');

        $rows = $this->actingAs($admin)
            ->getJson('/api/admin/mcp-tool-call-audit')
            ->assertOk()
            ->json('data');

        $ids = array_column($rows, 'id');
        $this->assertNotContains((int) $otherAudit->id, $ids);
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name' => 'MCP Admin',
            'email' => 'mcp-admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'MCP Super',
            'email' => 'mcp-super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function makeViewer(): User
    {
        $user = User::create([
            'name' => 'MCP Viewer',
            'email' => 'mcp-viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createServer(User $user, array $overrides = []): McpServer
    {
        return McpServer::create(array_merge([
            'tenant_id' => app(TenantContext::class)->current(),
            'name' => 'server-'.uniqid(),
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://127.0.0.1:3535',
            'enabled_tools_json' => ['*'],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $user->id,
        ], $overrides));
    }

    private function createAuditRow(
        User $user,
        McpServer $server,
        string $status,
        string $toolName,
        ?Carbon $createdAt = null,
        ?string $tenantId = null,
    ): McpToolCallAudit
    {
        return McpToolCallAudit::create([
            'tenant_id' => $tenantId ?? app(TenantContext::class)->current(),
            'user_id' => $user->id,
            'mcp_server_id' => $server->id,
            'tool_name' => $toolName,
            'input_json_redacted' => ['raw' => 'input'],
            'result_hash' => hash('sha256', uniqid('', true)),
            'duration_ms' => 33,
            'status' => $status,
            'error_json' => $status === McpToolCallAudit::STATUS_OK ? null : ['message' => 'boom'],
            'created_at' => $createdAt?->toDateTimeString() ?? now(),
            'updated_at' => $createdAt?->toDateTimeString() ?? now(),
        ]);
    }
}
