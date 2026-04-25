<?php

namespace Tests\Feature\Rbac;

use App\Http\Middleware\EnsureProjectAccess;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EnsureProjectAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware(['api', 'auth', EnsureProjectAccess::class])
            ->get('/api/probe', fn () => response()->json(['ok' => true]));
    }

    public function test_member_with_matching_header_gets_200(): void
    {
        $user = $this->makeUser('member@example.com');
        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
        ]);

        $this->actingAs($user);

        $this->getJson('/api/probe', ['X-Project-Key' => 'hr-portal'])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_non_member_gets_403_with_project_key_payload(): void
    {
        $user = $this->makeUser('outsider@example.com');
        $this->actingAs($user);

        $this->getJson('/api/probe', ['X-Project-Key' => 'hr-portal'])
            ->assertStatus(403)
            ->assertJson([
                'message' => 'You do not have access to project hr-portal.',
                'project_key' => 'hr-portal',
            ]);
    }

    public function test_kb_read_any_permission_bypasses_membership_check(): void
    {
        Permission::findOrCreate('kb.read.any', 'web');
        $role = Role::findOrCreate('super-admin', 'web');
        $role->syncPermissions(['kb.read.any']);

        $user = $this->makeUser('root@example.com');
        $user->assignRole('super-admin');
        $this->actingAs($user);

        $this->getJson('/api/probe', ['X-Project-Key' => 'hr-portal'])
            ->assertOk();
    }

    public function test_no_project_key_in_request_passes_through(): void
    {
        $user = $this->makeUser('passthrough@example.com');
        $this->actingAs($user);

        $this->getJson('/api/probe')
            ->assertOk();
    }

    public function test_rbac_disabled_passes_through(): void
    {
        config()->set('rbac.enforced', false);

        $user = $this->makeUser('disabled@example.com');
        $this->actingAs($user);

        $this->getJson('/api/probe', ['X-Project-Key' => 'hr-portal'])
            ->assertOk();
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }
}
