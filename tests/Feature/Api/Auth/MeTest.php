<?php

namespace Tests\Feature\Api\Auth;

use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}
