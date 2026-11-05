<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use App\Routines\WikiMaintenanceRoutineTarget;
use App\Services\Kb\AutoWiki\WikiMaintainer;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Padosoft\Routines\Targets\TargetRegistry;
use Tests\TestCase;

/**
 * v8.39/W5 (ADR 0033 §8) — the tenant-scoped, RBAC-gated HTTP surface for
 * the Auto-Wiki maintenance routine. R43: `index()` (GET) always answers,
 * even off; `run()` (POST) requires the flag on and `role:admin|super-admin`
 * (R32, covered here + the AdminAuthorizationMatrix for the GET).
 */
final class KbWikiRoutineControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = app(TenantContext::class)->current();
        $this->seed(RbacSeeder::class);
        $this->withHeaders(['Accept' => 'application/json']);
    }

    private function bindMaintainerAndRegisterTarget(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(WikiMaintainer::class);
        $this->app->instance(WikiMaintainer::class, $mock);
        app(TargetRegistry::class)->register(app(WikiMaintenanceRoutineTarget::class));

        return $mock;
    }

    private function makeAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeViewer(): User
    {
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $viewer->assignRole('viewer');

        return $viewer;
    }

    public function test_index_with_the_flag_off_returns_disabled(): void
    {
        config(['kb.wiki_routine.enabled' => false]);

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/admin/kb/wiki-routine')
            ->assertOk()
            ->assertJson(['disabled' => true, 'flag' => 'KB_WIKI_ROUTINE_ENABLED']);
    }

    public function test_index_reports_not_provisioned_when_enabled_but_no_routine_yet(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        $this->bindMaintainerAndRegisterTarget();

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/admin/kb/wiki-routine')
            ->assertOk()
            ->assertJsonPath('data.provisioned', false);
    }

    public function test_run_provisions_and_fires_the_routine(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        $mock = $this->bindMaintainerAndRegisterTarget();
        $mock->shouldReceive('maintain')->once()
            ->andReturn(['projects' => [], 'lint_issues' => 0, 'backfilled' => 1, 'fixed' => 0]);

        $this->actingAs($this->makeAdmin())
            ->postJson('/api/admin/kb/wiki-routine/run')
            ->assertStatus(202)
            ->assertJsonPath('data.outcome', 'succeeded');

        $this->assertDatabaseHas('routines', [
            'target_type' => WikiMaintenanceRoutineTarget::TYPE,
            'organization_id' => $this->tenantId,
        ]);
    }

    public function test_run_with_the_flag_off_returns_disabled_never_a_partial_run(): void
    {
        config(['kb.wiki_routine.enabled' => false]);

        $this->actingAs($this->makeAdmin())
            ->postJson('/api/admin/kb/wiki-routine/run')
            ->assertOk()
            ->assertJson(['disabled' => true]);

        $this->assertDatabaseCount('routines', 0);
    }

    public function test_a_viewer_is_forbidden_from_both_actions(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        $this->bindMaintainerAndRegisterTarget();
        $viewer = $this->makeViewer();

        $this->actingAs($viewer)->getJson('/api/admin/kb/wiki-routine')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/admin/kb/wiki-routine/run')->assertForbidden();
    }

    public function test_two_tenants_see_independent_status_r30(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        $mock = $this->bindMaintainerAndRegisterTarget();
        $mock->shouldReceive('maintain')->once()->andReturn(['projects' => [], 'lint_issues' => 0, 'backfilled' => 0, 'fixed' => 0]);

        $this->actingAs($this->makeAdmin())->postJson('/api/admin/kb/wiki-routine/run')->assertStatus(202);

        $tenants = app(TenantContext::class);
        $tenants->set('other-tenant');
        $this->actingAs($this->makeAdmin())
            ->getJson('/api/admin/kb/wiki-routine')
            ->assertOk()
            ->assertJsonPath('data.provisioned', false);
    }
}
