<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Tests\TestCase;

/**
 * PR7 / Phase F2 — admin users CRUD.
 *
 * Mirrors DashboardMetricsControllerTest mounting (routes/api.php under the
 * `api` middleware group), seeds RbacSeeder in setUp, flushes the Laravel
 * cache so the Spatie permission cache doesn't survive DB rollback under
 * Testbench (PR6 LESSONS).
 */
class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'users-test';

    private const PROJECT = 'users-project';

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Tenant::query()->updateOrCreate(
            ['slug' => self::TENANT],
            ['name' => 'Users Test', 'status' => 'active', 'is_system' => false],
        );
        app(TenantContext::class)->set(self::TENANT);
        $this->withHeader('X-Tenant-Id', self::TENANT);
        Project::firstOrCreate(
            ['tenant_id' => self::TENANT, 'project_key' => self::PROJECT],
            ['name' => 'Users Project', 'description' => 'Test project'],
        );
        Cache::flush();
    }

    // ------------------------------------------------------------------
    // Index — pagination + filters
    // ------------------------------------------------------------------

    public function test_index_returns_paginated_list_for_admin(): void
    {
        $admin = $this->makeAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->makeViewer("viewer-{$i}");
        }

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/users?per_page=3')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'email', 'is_active', 'roles']],
                'links',
                'meta' => ['current_page', 'per_page', 'total'],
            ]);

        $this->assertSame(3, $response->json('meta.per_page'));
    }

    public function test_index_search_q_matches_name_and_email(): void
    {
        $admin = $this->makeAdmin();
        $this->makeViewer('alice', 'alice@demo.local');
        $this->makeViewer('bob', 'bob@demo.local');

        $byName = $this->actingAs($admin)
            ->getJson('/api/admin/users?q=alice')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $byName);
        $this->assertSame('alice@demo.local', $byName[0]['email']);

        $byEmail = $this->actingAs($admin)
            ->getJson('/api/admin/users?q=bob@')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $byEmail);
    }

    public function test_index_role_filter_narrows_results(): void
    {
        $admin = $this->makeAdmin();
        $viewer = $this->makeViewer('only-viewer');

        $data = $this->actingAs($admin)
            ->getJson('/api/admin/users?role=viewer')
            ->assertOk()
            ->json('data');

        $ids = array_column($data, 'id');
        $this->assertContains($viewer->id, $ids);
        $this->assertNotContains($admin->id, $ids);
    }

    public function test_index_active_filter_hides_inactive_users(): void
    {
        $admin = $this->makeAdmin();
        $inactive = $this->makeViewer('inactive');
        $inactive->is_active = false;
        $inactive->save();

        $onlyActive = $this->actingAs($admin)
            ->getJson('/api/admin/users?active=1')
            ->assertOk()
            ->json('data');

        $this->assertNotContains($inactive->id, array_column($onlyActive, 'id'));
    }

    public function test_index_with_trashed_surfaces_soft_deleted(): void
    {
        $admin = $this->makeAdmin();
        $gone = $this->makeViewer('gone');
        $gone->delete();

        $default = $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json('data');
        $this->assertNotContains($gone->id, array_column($default, 'id'));

        $withTrashed = $this->actingAs($admin)
            ->getJson('/api/admin/users?with_trashed=1')
            ->assertOk()
            ->json('data');
        $this->assertContains($gone->id, array_column($withTrashed, 'id'));
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_user_with_roles_and_permissions(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeViewer('target');

        $this->actingAs($admin)
            ->getJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.email', 'target@demo.local')
            ->assertJsonStructure(['data' => ['roles', 'permissions']]);
    }

    public function test_index_and_show_exclude_identities_outside_the_current_tenant(): void
    {
        $admin = $this->makeAdmin();
        $local = $this->makeViewer('local');
        $foreign = User::create([
            'name' => 'Foreign',
            'email' => 'foreign@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $foreign->assignRole('viewer');
        ProjectMembership::create([
            'tenant_id' => 'foreign',
            'user_id' => $foreign->id,
            'project_key' => 'foreign',
            'role' => 'member',
        ]);

        $data = $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json('data');

        $ids = array_column($data, 'id');
        $this->assertContains($local->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->actingAs($admin)
            ->getJson("/api/admin/users/{$foreign->id}")
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Store
    // ------------------------------------------------------------------

    public function test_store_creates_user_with_default_viewer_role(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [
                'name' => 'New Person',
                'email' => 'new-person@demo.local',
                'password' => 'Super$tr0ngP@ss1',
                'initial_project_key' => self::PROJECT,
                'membership_role' => 'member',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.email', 'new-person@demo.local')
            ->assertJsonPath('data.roles', ['viewer']);

        $this->assertDatabaseHas('users', ['email' => 'new-person@demo.local']);
        $createdId = (int) User::query()->where('email', 'new-person@demo.local')->value('id');
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => self::TENANT,
            'user_id' => $createdId,
            'project_key' => self::PROJECT,
            'role' => 'member',
        ]);
    }

    public function test_store_rejects_duplicate_email_with_422(): void
    {
        $admin = $this->makeAdmin();
        $this->makeViewer('dup', 'dup@demo.local');

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [
                'name' => 'Dup',
                'email' => 'dup@demo.local',
                'password' => 'Super$tr0ngP@ss1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_store_uses_legacy_email_identity_before_normalized_column_migration(): void
    {
        $admin = $this->makeAdmin();
        $this->makeViewer('dup', 'dup@demo.local');

        $response = $this->withLegacyEmailSchema(
            fn () => $this->actingAs($admin)
                ->postJson('/api/admin/users', [
                    'name' => 'Dup',
                    'email' => 'dup@demo.local',
                    'password' => 'Super$tr0ngP@ss1',
                    'initial_project_key' => self::PROJECT,
                    'membership_role' => 'member',
                ]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_store_rejects_missing_required_fields(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'email',
                'password',
                'initial_project_key',
                'membership_role',
            ]);
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_partial_patch_updates_only_provided_fields(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('original', 'original@demo.local');
        $originalHash = $user->password;

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$user->id}", [
                'name' => 'Renamed',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.email', 'original@demo.local');

        $user->refresh();
        $this->assertSame($originalHash, $user->password, 'password must not be rehashed on partial update');
    }

    public function test_update_rehashes_password_when_provided(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('pwd');
        $originalHash = $user->password;

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$user->id}", [
                'password' => 'Super$tr0ngP@ss1',
            ])
            ->assertOk();

        $user->refresh();
        $this->assertNotSame($originalHash, $user->password);
    }

    public function test_update_uses_legacy_email_identity_before_normalized_column_migration(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeViewer('target');
        $this->makeViewer('existing', 'existing@demo.local');

        $response = $this->withLegacyEmailSchema(
            fn () => $this->actingAs($admin)
                ->patchJson("/api/admin/users/{$target->id}", [
                    'email' => 'existing@demo.local',
                ]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertSame('target@demo.local', $target->fresh()->email);
    }

    public function test_tenant_surface_rejects_global_identity_mutation_when_another_tenant_depends_on_it(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('shared');
        ProjectMembership::create([
            'tenant_id' => 'other-tenant',
            'user_id' => $user->id,
            'project_key' => 'other-project',
            'role' => 'member',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$user->id}", ['name' => 'Changed'])
            ->assertConflict()
            ->assertJsonPath('error', 'cross_tenant_identity');

        $this->assertSame('shared', $user->fresh()->name);
    }

    // ------------------------------------------------------------------
    // Destroy — soft + force
    // ------------------------------------------------------------------

    public function test_destroy_soft_deletes_user(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('kill');

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$user->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_destroy_force_hard_deletes_user(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('nuke');

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$user->id}?force=1")
            ->assertStatus(204);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_destroy_self_blocked_with_422(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_destroy_last_super_admin_blocked_with_409(): void
    {
        $admin = $this->makeAdmin();
        $superAdmin = User::create([
            'name' => 'Super',
            'email' => 'super@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $superAdmin->assignRole('super-admin');
        $this->grantTenantMembership($superAdmin);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$superAdmin->id}")
            ->assertStatus(409);
    }

    // ------------------------------------------------------------------
    // Restore / toggleActive / resendInvite
    // ------------------------------------------------------------------

    public function test_restore_rehydrates_soft_deleted_user(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('back');
        $user->delete();

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.deleted_at', null);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
    }

    public function test_toggle_active_flips_when_no_body(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('toggle');
        $this->assertTrue((bool) $user->is_active);

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$user->id}/active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_toggle_active_honours_explicit_value(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('set');

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$user->id}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_resend_invite_acknowledges_with_202(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('invite');

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/resend-invite")
            ->assertStatus(202)
            ->assertJsonPath('user_id', $user->id);
    }

    // ------------------------------------------------------------------
    // Privilege-escalation ceiling (security review v8.8)
    // ------------------------------------------------------------------

    public function test_admin_cannot_create_user_with_super_admin_role(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [
                'name' => 'Climber',
                'email' => 'climber@demo.local',
                'password' => 'Super$tr0ngP@ss1',
                'roles' => ['super-admin'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles']);

        $this->assertDatabaseMissing('users', ['email' => 'climber@demo.local']);
    }

    public function test_admin_cannot_promote_existing_user_to_super_admin(): void
    {
        $admin = $this->makeAdmin();
        $victim = $this->makeViewer('puppet');

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$victim->id}", [
                'roles' => ['super-admin'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles']);

        $victim->refresh();
        $this->assertFalse($victim->hasRole('super-admin'));
    }

    public function test_admin_can_still_assign_subordinate_roles(): void
    {
        $admin = $this->makeAdmin();

        // editor + viewer carry only permissions admin already holds, so the
        // ceiling must NOT block them (no privilege amplification).
        $this->actingAs($admin)
            ->postJson('/api/admin/users', [
                'name' => 'Editor Person',
                'email' => 'editor-person@demo.local',
                'password' => 'Super$tr0ngP@ss1',
                'roles' => ['editor', 'viewer'],
                'initial_project_key' => self::PROJECT,
                'membership_role' => 'member',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.roles', ['editor', 'viewer']);
    }

    public function test_super_admin_can_assign_super_admin_role(): void
    {
        $superAdmin = User::create([
            'name' => 'Root',
            'email' => 'root@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $superAdmin->assignRole('super-admin');
        $this->grantTenantMembership($superAdmin);

        $this->actingAs($superAdmin)
            ->postJson('/api/admin/users', [
                'name' => 'Second Root',
                'email' => 'second-root@demo.local',
                'password' => 'Super$tr0ngP@ss1',
                'roles' => ['super-admin'],
                'initial_project_key' => self::PROJECT,
                'membership_role' => 'admin',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.roles', ['super-admin']);
    }

    public function test_system_admin_role_cannot_be_assigned_or_mutated_through_users_crud(): void
    {
        $system = User::create([
            'name' => 'System operator',
            'email' => 'system-operator@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $system->assignRole(['system-admin', 'super-admin']);
        $this->grantTenantMembership($system);

        $this->actingAs($system)
            ->postJson('/api/admin/users', [
                'name' => 'Forbidden operator',
                'email' => 'forbidden-operator@demo.local',
                'password' => 'Super$tr0ngP@ss1',
                'roles' => ['system-admin'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);

        $this->actingAs($system)
            ->patchJson("/api/admin/users/{$system->id}", ['name' => 'Renamed'])
            ->assertConflict();
        $this->actingAs($system)
            ->patchJson("/api/admin/users/{$system->id}/active", ['is_active' => false])
            ->assertConflict();

        $system->refresh();
        $this->assertSame('System operator', $system->name);
        $this->assertTrue($system->is_active);
    }

    public function test_tenant_admin_cannot_list_or_show_system_admin_accounts(): void
    {
        $admin = $this->makeAdmin();
        $system = User::create([
            'name' => 'Hidden operator',
            'email' => 'hidden-system@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $system->assignRole(['system-admin', 'super-admin']);
        $this->grantTenantMembership($system);

        $data = $this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->json('data');
        $this->assertNotContains($system->id, array_column($data, 'id'));
        $this->actingAs($admin)->getJson("/api/admin/users/{$system->id}")->assertNotFound();
    }

    // ------------------------------------------------------------------
    // RBAC — non-admin / guest
    // ------------------------------------------------------------------

    public function test_non_admin_gets_403(): void
    {
        $viewer = $this->makeViewer('rbac');

        $this->actingAs($viewer)
            ->getJson('/api/admin/users')
            ->assertStatus(403);

        $this->actingAs($viewer)
            ->postJson('/api/admin/users', [])
            ->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');
        $this->grantTenantMembership($admin);

        return $admin;
    }

    private function makeViewer(string $slug, ?string $email = null): User
    {
        $user = User::create([
            'name' => $slug,
            'email' => $email ?? $slug.'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');
        $this->grantTenantMembership($user);

        return $user;
    }

    private function grantTenantMembership(User $user): void
    {
        ProjectMembership::firstOrCreate([
            'tenant_id' => self::TENANT,
            'user_id' => $user->id,
            'project_key' => self::PROJECT,
        ], [
            'role' => 'member',
        ]);
    }

    private function withLegacyEmailSchema(callable $callback): mixed
    {
        $migration = require dirname(__DIR__, 4).'/database/migrations/2026_07_27_000001_add_email_normalized_to_users_table.php';
        $migration->down();
        Once::flush();

        try {
            return $callback();
        } finally {
            $migration->up();
            Once::flush();
        }
    }
}
