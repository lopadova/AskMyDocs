<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    public function test_authenticated_logout_returns_204_and_ends_session(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/auth/logout');

        $response->assertNoContent(204);
    }

    public function test_unauthenticated_logout_returns_401(): void
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(401);
        $this->assertFalse(Auth::check());
    }
}
