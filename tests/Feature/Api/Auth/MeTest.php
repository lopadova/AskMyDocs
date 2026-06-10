<?php

namespace Tests\Feature\Api\Auth;

use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    public function test_authenticated_me_returns_user_shape_with_empty_rbac_arrays(): void
    {
        $user = User::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJson([
                'user' => [
                    'id' => $user->id,
                    'name' => 'Ada Lovelace',
                    'email' => 'ada@example.com',
                ],
                'roles' => [],
                'permissions' => [],
                'projects' => [],
                'preferences' => [
                    'theme' => 'dark',
                    'density' => 'balanced',
                    'language' => 'en',
                ],
            ]);
    }

    public function test_authenticated_me_with_role_and_membership_populates_rbac_arrays(): void
    {
        Permission::findOrCreate('kb.read.any', 'web');
        Permission::findOrCreate('users.manage', 'web');
        $role = Role::findOrCreate('admin', 'web');
        $role->syncPermissions(['kb.read.any', 'users.manage']);

        $user = User::create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('admin');

        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'admin',
            'scope_allowlist' => ['folder_globs' => ['hr/*']],
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('user.email', 'grace@example.com')
            ->assertJsonPath('roles.0', 'admin')
            ->assertJsonPath('projects.0.project_key', 'hr-portal')
            ->assertJsonPath('projects.0.role', 'admin')
            ->assertJsonPath('projects.0.scope.folder_globs.0', 'hr/*');

        $permissions = $response->json('permissions');
        $this->assertContains('kb.read.any', $permissions);
        $this->assertContains('users.manage', $permissions);
    }

    public function test_unauthenticated_me_returns_401(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_me_teams_falls_back_to_default_for_user_without_memberships(): void
    {
        $user = $this->makeUser('nomember@example.com');

        $this->actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonCount(1, 'teams')
            ->assertJsonPath('teams.0.tenant_id', 'default')
            ->assertJsonPath('teams.0.name', 'Default')
            ->assertJsonPath('teams.0.projects', []);
    }

    public function test_me_teams_groups_memberships_per_tenant_with_default_first(): void
    {
        $user = $this->makeUser('multi@example.com');

        ProjectMembership::create([
            'tenant_id' => 'zeta-corp',
            'user_id' => $user->id,
            'project_key' => 'zeta-kb',
            'role' => 'editor',
            'scope_allowlist' => null,
        ]);
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'admin',
            'scope_allowlist' => ['folder_globs' => ['docs/*']],
        ]);
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-legal',
            'role' => 'viewer',
            'scope_allowlist' => null,
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/auth/me')->assertOk();

        // default first (bootstrap team), then alphabetical.
        $response->assertJsonPath('teams.0.tenant_id', 'default')
            ->assertJsonPath('teams.1.tenant_id', 'acme')
            ->assertJsonPath('teams.2.tenant_id', 'zeta-corp')
            ->assertJsonCount(3, 'teams')
            ->assertJsonCount(2, 'teams.1.projects')
            ->assertJsonPath('teams.1.projects.0.project_key', 'acme-kb')
            ->assertJsonPath('teams.1.projects.0.role', 'admin')
            ->assertJsonPath('teams.1.projects.0.scope.folder_globs.0', 'docs/*')
            ->assertJsonPath('teams.2.projects.0.project_key', 'zeta-kb');
    }

    public function test_me_teams_uses_tenants_table_label_with_humanised_fallback(): void
    {
        Tenant::create(['slug' => 'acme', 'name' => 'Acme Corporation']);

        $user = $this->makeUser('labels@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'admin',
        ]);
        ProjectMembership::create([
            'tenant_id' => 'no-row-tenant',
            'user_id' => $user->id,
            'project_key' => 'misc',
            'role' => 'viewer',
        ]);

        $this->actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('teams.1.tenant_id', 'acme')
            ->assertJsonPath('teams.1.name', 'Acme Corporation')
            ->assertJsonPath('teams.2.tenant_id', 'no-row-tenant')
            ->assertJsonPath('teams.2.name', 'No Row Tenant');
    }

    public function test_me_teams_includes_all_active_tenants_for_cross_access_user(): void
    {
        Tenant::create(['slug' => 'acme', 'name' => 'Acme Corporation']);
        Tenant::create(['slug' => 'globex', 'name' => 'Globex']);
        Tenant::create(['slug' => 'frozen-co', 'name' => 'Frozen Co', 'status' => 'suspended']);

        Permission::findOrCreate('tenant.cross-access', 'web');
        $user = $this->makeUser('operator@example.com');
        $user->givePermissionTo('tenant.cross-access');

        $this->actingAs($user);

        $response = $this->getJson('/api/auth/me')->assertOk();

        $tenantIds = array_column($response->json('teams'), 'tenant_id');
        $this->assertSame(['default', 'acme', 'globex'], $tenantIds);
        $this->assertNotContains('frozen-co', $tenantIds, 'suspended tenants must not be offered as teams');

        // Cross-access teams without memberships expose an empty projects
        // list — the FE derives project options from tenant-scoped
        // endpoints (R18), not from this payload.
        $this->assertSame([], $response->json('teams.1.projects'));
    }

    public function test_me_teams_extension_is_additive_and_leaves_legacy_keys_untouched(): void
    {
        $user = $this->makeUser('contract@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'admin',
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/auth/me')->assertOk();

        // R27 — legacy `projects` stays the flat cross-tenant membership
        // list it has always been, even though `teams` now groups it.
        $response->assertJsonPath('projects.0.project_key', 'acme-kb')
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'email_verified_at'],
                'roles',
                'permissions',
                'projects',
                'teams' => [['tenant_id', 'name', 'projects']],
                'preferences' => ['theme', 'density', 'language'],
            ]);
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'name' => 'Team Tester',
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }
}
