<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cross-tenant isolation regression suite (R30) for the admin surface.
 *
 * Covers the CRITICAL/HIGH findings from the 2026-05 deep review:
 *   - C1: X-Tenant-Id header authorization (AuthorizeTenantHeader).
 *   - H1: TagController read paths (index + find) scoped to tenant.
 *   - (representative) the forTenant() read-scope pattern applied across
 *     LogViewer / KbTree / MaintenanceCommand controllers — kb_tags is
 *     the simplest tenant-aware CRUD and exercises the identical pattern.
 *
 * kb_tags is seeded in two tenants ('acme', 'umbrella'). A super-admin
 * (holds `tenant.cross-access`) drives the active tenant via the
 * X-Tenant-Id header and must only ever see / resolve the rows of the
 * tenant they pointed at. The systemic guard against NEW unscoped reads
 * lives in tests/Architecture/TenantReadScopeTest.php.
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        // Production prepends ResolveTenant globally (bootstrap/app.php);
        // Testbench does not execute that file, so wire it onto the api
        // group here so the X-Tenant-Id header actually drives the active
        // tenant the same way it does in production.
        $router->middleware(['api', \App\Http\Middleware\ResolveTenant::class])
            ->prefix('api')
            ->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    // ---- C1: X-Tenant-Id header authorization -----------------------

    public function test_regular_admin_cannot_switch_tenant_via_header(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/api/admin/kb/tags')
            ->assertStatus(403)
            ->assertJsonPath('error', 'tenant_forbidden');
    }

    public function test_super_admin_may_switch_tenant_via_header(): void
    {
        $super = $this->makeUser('super-admin');

        $this->actingAs($super)
            ->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/api/admin/kb/tags')
            ->assertOk();
    }

    public function test_regular_admin_without_header_operates_in_default_tenant(): void
    {
        $admin = $this->makeUser('admin');
        $this->seedTag('default', 'hr', 'policy');

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/tags')->assertOk();

        $this->assertCount(1, $resp->json('data'));
        $this->assertSame('policy', $resp->json('data.0.slug'));
    }

    // ---- H1: read-scope + IDOR via the tenant context ---------------

    public function test_tag_list_is_scoped_to_the_active_tenant(): void
    {
        $super = $this->makeUser('super-admin');
        $this->seedTag('acme', 'hr', 'acme-only');
        $this->seedTag('umbrella', 'hr', 'umbrella-only');

        $acme = $this->actingAs($super)
            ->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/api/admin/kb/tags')->assertOk();
        $this->assertSame(['acme-only'], collect($acme->json('data'))->pluck('slug')->all());

        $umbrella = $this->actingAs($super)
            ->withHeader('X-Tenant-Id', 'umbrella')
            ->getJson('/api/admin/kb/tags')->assertOk();
        $this->assertSame(['umbrella-only'], collect($umbrella->json('data'))->pluck('slug')->all());
    }

    public function test_cannot_show_a_tag_owned_by_another_tenant(): void
    {
        $super = $this->makeUser('super-admin');
        $foreignTagId = $this->seedTag('umbrella', 'hr', 'secret');

        // Active tenant = acme; the umbrella tag id must 404 (IDOR guard).
        $this->actingAs($super)
            ->withHeader('X-Tenant-Id', 'acme')
            ->getJson("/api/admin/kb/tags/{$foreignTagId}")
            ->assertStatus(404);
    }

    public function test_cannot_delete_a_tag_owned_by_another_tenant(): void
    {
        $super = $this->makeUser('super-admin');
        $foreignTagId = $this->seedTag('umbrella', 'hr', 'secret');

        $this->actingAs($super)
            ->withHeader('X-Tenant-Id', 'acme')
            ->deleteJson("/api/admin/kb/tags/{$foreignTagId}")
            ->assertStatus(404);

        // R16 — prove the bystander row was never touched.
        $this->assertDatabaseHas('kb_tags', ['id' => $foreignTagId, 'tenant_id' => 'umbrella']);
    }

    public function test_cannot_verify_a_compliance_report_owned_by_another_tenant(): void
    {
        // C4 (R30) — the {report} binding is tenant-scoped.
        $super = $this->makeUser('super-admin');
        $foreignReportId = (int) DB::table('compliance_reports')->insertGetId([
            'tenant_id' => 'umbrella',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'payload_json' => json_encode(['delta' => [], 'audit' => [], 'period' => []]),
            'hash_sha256' => hash('sha256', 'x'),
            'hash_hmac' => hash('sha256', 'y'),
            'generated_at' => now(),
            'generated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($super)
            ->withHeader('X-Tenant-Id', 'acme')
            ->postJson("/api/admin/compliance/reports/{$foreignReportId}/verify")
            ->assertStatus(404);
    }

    public function test_same_slug_and_project_coexist_across_tenants(): void
    {
        // R30/R31 — two tenants legitimately share (project_key, slug).
        $this->seedTag('acme', 'hr', 'policy');
        $this->seedTag('umbrella', 'hr', 'policy');

        $this->assertSame(2, DB::table('kb_tags')->where('slug', 'policy')->where('project_key', 'hr')->count());
    }

    // -----------------------------------------------------------------

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function seedTag(string $tenantId, string $projectKey, string $slug): int
    {
        return (int) DB::table('kb_tags')->insertGetId([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'slug' => $slug,
            'label' => ucfirst($slug),
            'color' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
