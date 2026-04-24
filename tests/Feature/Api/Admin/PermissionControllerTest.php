<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PR7 / Phase F2 — permission catalogue (read-only).
 */
class PermissionControllerTest extends TestCase
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

    public function test_index_returns_flat_and_grouped_permissions(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/permissions')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'guard_name']],
                'grouped',
            ]);

        $grouped = $response->json('grouped');
        $this->assertArrayHasKey('kb', $grouped, 'kb domain must be grouped');
        $this->assertArrayHasKey('users', $grouped);
        $this->assertArrayHasKey('logs', $grouped);
    }

    public function test_grouped_keys_sorted_deterministically(): void
    {
        $admin = $this->makeAdmin();

        $grouped = $this->actingAs($admin)
            ->getJson('/api/admin/permissions')
            ->assertOk()
            ->json('grouped');

        $keys = array_keys($grouped);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'grouped keys must be alphabetically sorted');
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)
            ->getJson('/api/admin/permissions')
            ->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/permissions')->assertStatus(401);
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
}
