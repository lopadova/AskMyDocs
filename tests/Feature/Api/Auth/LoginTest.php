<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Load the real application auth routes under the `api` prefix + middleware
     * group so we exercise the full stack (api + web → Sanctum stateful →
     * throttle:login) the way bootstrap/app.php does in production.
     */
    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('test@example.com|127.0.0.1');
    }

    private function makeUser(string $password = 'secret123'): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make($password),
        ]);
    }

    public function test_login_with_valid_credentials_returns_200_and_user(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'abilities']);

        $this->assertTrue(Auth::check());
    }

    public function test_login_with_wrong_password_returns_422(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertFalse(Auth::check());
    }

    public function test_login_with_unknown_email_returns_422(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertFalse(Auth::check());
    }

    public function test_login_throttled_after_five_failed_attempts(): void
    {
        $this->makeUser();

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // The sixth attempt must hit the throttle middleware (429).
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }

    public function test_login_missing_fields_returns_422_without_touching_auth(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
        $this->assertFalse(Auth::check());
    }
}
