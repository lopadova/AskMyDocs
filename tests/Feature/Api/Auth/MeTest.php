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

        $this->actingAsWithoutTenant($user);

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
                'onboarding' => [
                    'required' => true,
                    'can_create_company' => true,
                ],
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

        $this->actingAsWithoutTenant($user);

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

    public function test_me_teams_is_empty_for_user_without_memberships(): void
    {
        $user = $this->makeUser('nomember@example.com');

        $this->actingAsWithoutTenant($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonCount(0, 'teams')
            ->assertJsonPath('onboarding.required', true);
    }

    public function test_me_teams_hash_is_deterministic_unique_and_url_safe(): void
    {
        $user = $this->makeUser('hash@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'admin',
        ]);

        $this->actingAsWithoutTenant($user);

        $teams = $this->getJson('/api/auth/me')->assertOk()->json('teams');

        $hashes = array_column($teams, 'hash');
        $this->assertSame($hashes, array_values(array_unique($hashes)), 'team hashes must be unique');
        foreach ($hashes as $hash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $hash);
        }

        // Deterministic: a second call yields the same routing segments —
        // bookmarked /app/{hash}/… URLs survive re-logins and deploys.
        $again = array_column($this->getJson('/api/auth/me')->json('teams'), 'hash');
        $this->assertSame($hashes, $again);
    }

    public function test_me_teams_groups_operational_memberships_and_hides_default(): void
    {
        $user = $this->makeUser('multi@example.com');

        ProjectMembership::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'legacy-kb',
            'role' => 'viewer',
            'scope_allowlist' => null,
        ]);
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

        $this->actingAsWithoutTenant($user);

        $response = $this->getJson('/api/auth/me')->assertOk();

        $response->assertJsonPath('teams.0.tenant_id', 'acme')
            ->assertJsonPath('teams.1.tenant_id', 'zeta-corp')
            ->assertJsonCount(2, 'teams')
            ->assertJsonCount(2, 'teams.0.projects')
            ->assertJsonPath('teams.0.projects.0.project_key', 'acme-kb')
            ->assertJsonPath('teams.0.projects.0.role', 'admin')
            ->assertJsonPath('teams.0.projects.0.scope.folder_globs.0', 'docs/*')
            ->assertJsonPath('teams.1.projects.0.project_key', 'zeta-kb')
            ->assertJsonPath('onboarding.required', false);
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

        $this->actingAsWithoutTenant($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('teams.0.tenant_id', 'acme')
            ->assertJsonPath('teams.0.name', 'Acme Corporation')
            ->assertJsonPath('teams.1.tenant_id', 'no-row-tenant')
            ->assertJsonPath('teams.1.name', 'No Row Tenant');
    }

    public function test_me_teams_for_system_admin_contains_only_active_membership_tenants(): void
    {
        Tenant::create(['slug' => 'acme', 'name' => 'Acme Corporation']);
        Tenant::create(['slug' => 'globex', 'name' => 'Globex']);
        Tenant::create(['slug' => 'frozen-co', 'name' => 'Frozen Co', 'status' => 'suspended']);

        $user = $this->makeUser('operator@example.com');
        $systemRole = Role::findOrCreate('system-admin', 'web');
        $user->assignRole($systemRole);
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'admin',
        ]);
        ProjectMembership::create([
            'tenant_id' => 'frozen-co',
            'user_id' => $user->id,
            'project_key' => 'frozen-kb',
            'role' => 'admin',
        ]);

        $this->actingAsWithoutTenant($user);

        $response = $this->getJson('/api/auth/me')->assertOk();

        $tenantIds = array_column($response->json('teams'), 'tenant_id');
        $this->assertSame(['acme'], $tenantIds);
        $this->assertNotContains('globex', $tenantIds, 'system role must not add unassociated tenants');
        $this->assertNotContains('frozen-co', $tenantIds, 'suspended tenants must not be offered as teams');
        $this->assertSame('acme-kb', $response->json('teams.0.projects.0.project_key'));
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

        $this->actingAsWithoutTenant($user);

        $response = $this->getJson('/api/auth/me')->assertOk();

        // R27 — legacy `projects` stays the flat cross-tenant membership
        // list it has always been, even though `teams` now groups it.
        $response->assertJsonPath('projects.0.project_key', 'acme-kb')
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'email_verified_at'],
                'roles',
                'permissions',
                'projects',
                'teams' => [['tenant_id', 'hash', 'name', 'projects']],
                'onboarding' => ['required', 'can_create_company'],
                'preferences' => ['theme', 'density', 'language'],
                'features' => ['invitations_admin', 'system_admin'],
            ]);
    }

    public function test_me_exposes_invitations_admin_feature_flag_in_both_states(): void
    {
        $user = $this->makeUser('flag@example.com');
        $this->actingAsWithoutTenant($user);

        $previous = config('invitations-admin.enabled', false);

        try {
            // OFF — the fresh-deploy state: the SPA must hide the Advanced launcher
            // so it never links to the unregistered /admin/invitations 404 (R43 OFF).
            config(['invitations-admin.enabled' => false]);
            $this->getJson('/api/auth/me')
                ->assertOk()
                ->assertJsonPath('features.invitations_admin', false);

            // ON — the package panel is mounted, so the launcher is offered (R43 ON).
            config(['invitations-admin.enabled' => true]);
            $this->getJson('/api/auth/me')
                ->assertOk()
                ->assertJsonPath('features.invitations_admin', true);
        } finally {
            config(['invitations-admin.enabled' => $previous]);
        }
    }

    public function test_me_exposes_system_admin_capability_from_platform_permission(): void
    {
        Permission::findOrCreate('platform.admin', 'web');
        $user = $this->makeUser('platform@example.com');
        $this->actingAsWithoutTenant($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('features.system_admin', false);

        $user->givePermissionTo('platform.admin');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('features.system_admin', true);
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
