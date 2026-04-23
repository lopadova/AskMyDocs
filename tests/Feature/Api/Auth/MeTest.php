<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
                // PR3 (RBAC) will populate these from Spatie + memberships.
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

    public function test_unauthenticated_me_returns_401(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }
}
