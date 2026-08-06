<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Admin\TeamRegistryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Tests\TestCase;

/**
 * v8.28 — admin HTTP surface for team (= tenant) create + rename. The
 * controller is a thin adapter over TeamRegistryService, so these tests
 * exercise the HTTP contract (status codes, envelope, validation, the
 * cross-tenant rename authorization, the 503 OFF-path mapping) — the deeper
 * domain invariants live in TeamRegistryServiceTest.
 */
final class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
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

    public function test_index_lists_only_real_membership_teams(): void
    {
        $admin = $this->makeUser('admin');
        app(TeamRegistryService::class)->create('acme', 'Acme Corp', $admin);

        $resp = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/api/admin/teams')
            ->assertOk();

        $slugs = array_column($resp->json('data'), 'slug');
        $this->assertContains('acme', $slugs);
        $this->assertNotContains('default', $slugs);

        $acme = collect($resp->json('data'))->firstWhere('slug', 'acme');
        $this->assertTrue($acme['can_manage']);
    }

    public function test_store_creates_a_team_and_returns_201(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->postJson('/api/admin/teams', ['name' => 'Acme Corp'])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'acme-corp')
            ->assertJsonPath('data.name', 'Acme Corp');

        $this->assertDatabaseHas('tenants', ['slug' => 'acme-corp', 'name' => 'Acme Corp']);
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme-corp',
            'user_id' => $admin->id,
            'project_key' => 'acme-corp',
        ]);
    }

    public function test_store_honours_an_explicit_slug(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->postJson('/api/admin/teams', ['name' => 'Acme Corp', 'slug' => 'acme'])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'acme');
    }

    public function test_store_rejects_a_blank_name_with_422(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->postJson('/api/admin/teams', ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_store_rejects_a_duplicate_slug_with_422(): void
    {
        $admin = $this->makeUser('admin');
        Tenant::create(['slug' => 'acme', 'name' => 'Existing']);

        $this->actingAs($admin)->postJson('/api/admin/teams', ['name' => 'Acme Corp', 'slug' => 'acme'])
            ->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_store_rejects_the_reserved_default_slug_with_422(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->postJson('/api/admin/teams', ['name' => 'X', 'slug' => 'default'])
            ->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_update_renames_a_manageable_team(): void
    {
        $admin = $this->makeUser('admin');
        app(TeamRegistryService::class)->create('acme', 'Acme Corp', $admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', 'acme')
            ->patchJson('/api/admin/teams/acme', ['name' => 'Acme Corporation'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Corporation');

        $this->assertDatabaseHas('tenants', ['slug' => 'acme', 'name' => 'Acme Corporation']);
    }

    public function test_update_404s_on_a_team_the_actor_cannot_manage(): void
    {
        // Team exists but belongs to another user; the admin has no membership
        // and must not be able to rename it.
        Tenant::create(['slug' => 'foreign', 'name' => 'Foreign Co']);
        $other = $this->makeUser('admin');
        ProjectMembership::create(['tenant_id' => 'foreign', 'user_id' => $other->id, 'project_key' => 'foreign', 'role' => 'admin']);

        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->patchJson('/api/admin/teams/foreign', ['name' => 'Hacked'])
            ->assertStatus(404);

        $this->assertDatabaseHas('tenants', ['slug' => 'foreign', 'name' => 'Foreign Co']);
    }

    public function test_update_rejects_a_blank_name_with_422(): void
    {
        $admin = $this->makeUser('admin');
        app(TeamRegistryService::class)->create('acme', 'Acme Corp', $admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', 'acme')
            ->patchJson('/api/admin/teams/acme', ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_store_maps_registry_unavailable_to_503(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantOperationalMembership($admin);

        // Simulate a deployment that never migrated the AI-Act package: the
        // service throws TeamRegistryUnavailableException, which the controller
        // must map to a clean 503 (R14/R43), never a 500 or a silent 200.
        Schema::shouldReceive('hasTable')->andReturn(false);

        $this->actingAs($admin)->postJson('/api/admin/teams', ['name' => 'Acme Corp'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'team_registry_unavailable');
    }

    public function test_index_still_returns_200_when_the_registry_table_is_absent(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantOperationalMembership($admin);

        // R43 OFF-path for the LIST surface: with the tenants table absent the
        // list must degrade to a clean 200 (humanised names), never a 500.
        Schema::shouldReceive('hasTable')->andReturn(false);

        $this->actingAs($admin)->getJson('/api/admin/teams')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->actingAs($viewer)->getJson('/api/admin/teams')->assertStatus(403);
        $this->actingAs($viewer)->postJson('/api/admin/teams', ['name' => 'X'])->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/teams')->assertStatus(401);
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function grantOperationalMembership(User $user): void
    {
        ProjectMembership::create([
            'tenant_id' => 'test-tenant',
            'user_id' => $user->id,
            'project_key' => 'test-project',
            'role' => 'admin',
        ]);
    }
}
