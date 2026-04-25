<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PR7 / Phase F2 — admin roles CRUD + permission sync.
 */
class RoleControllerTest extends TestCase
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

    public function test_index_lists_roles_with_users_count(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/roles')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'permissions', 'users_count']],
            ]);

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('super-admin', $names);
        $this->assertContains('admin', $names);
        $this->assertContains('editor', $names);
        $this->assertContains('viewer', $names);
    }

    public function test_store_creates_role_with_permissions(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/roles', [
                'name' => 'auditor',
                'permissions' => ['logs.view', 'insights.view'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'auditor');

        // Permission ordering is driven by the underlying Spatie pivot
        // insertion order, which isn't a stable contract — compare as a
        // set. Sorting both sides also guards against array_values
        // drift between Spatie releases.
        $actual = $response->json('data.permissions');
        sort($actual);
        $this->assertSame(['insights.view', 'logs.view'], $actual);
    }

    public function test_store_rejects_duplicate_name(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/roles', ['name' => 'admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_syncs_permissions(): void
    {
        $admin = $this->makeAdmin();
        $role = Role::create(['name' => 'tmp', 'guard_name' => 'web']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/roles/{$role->id}", [
                'permissions' => ['logs.view'],
            ])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['logs.view']);
    }

    public function test_update_blocks_rename_on_system_role_with_409(): void
    {
        $admin = $this->makeAdmin();
        $superAdminRole = Role::findByName('super-admin', 'web');

        $this->actingAs($admin)
            ->patchJson("/api/admin/roles/{$superAdminRole->id}", [
                'name' => 'hacker',
            ])
            ->assertStatus(409);
    }

    public function test_destroy_blocks_system_role_with_409(): void
    {
        $admin = $this->makeAdmin();
        $adminRole = Role::findByName('admin', 'web');

        $this->actingAs($admin)
            ->deleteJson("/api/admin/roles/{$adminRole->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('roles', ['id' => $adminRole->id]);
    }

    public function test_destroy_deletes_custom_role(): void
    {
        $admin = $this->makeAdmin();
        $role = Role::create(['name' => 'custom', 'guard_name' => 'web']);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/roles/{$role->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = $this->makeViewer();

        $this->actingAs($viewer)
            ->getJson('/api/admin/roles')
            ->assertStatus(403);

        $this->actingAs($viewer)
            ->postJson('/api/admin/roles', ['name' => 'nope'])
            ->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/roles')->assertStatus(401);
    }

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

    private function makeViewer(): User
    {
        $user = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');

        return $user;
    }
}
