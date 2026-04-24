<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
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

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
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
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.email', 'new-person@demo.local')
            ->assertJsonPath('data.roles', ['viewer']);

        $this->assertDatabaseHas('users', ['email' => 'new-person@demo.local']);
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

    public function test_store_rejects_missing_required_fields(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
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

        return $user;
    }
}
