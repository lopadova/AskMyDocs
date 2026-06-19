<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * POST /api/auth/token — Bearer-token issuance for non-browser clients
 * (the Tauri desktop demo). Verified end-to-end: the plaintext token returned
 * must actually authenticate a real auth:sanctum-protected route.
 */
class TokenTest extends TestCase
{
    use RefreshDatabase;

    private const THROTTLE_KEY = 'token|test@example.com|127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear(self::THROTTLE_KEY);
    }

    private function makeUser(string $password = 'secret123'): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make($password),
        ]);
    }

    public function test_valid_credentials_return_201_with_a_usable_bearer_token(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/token', [
            'email' => $user->email,
            'password' => 'secret123',
            'device_name' => 'my-laptop',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'my-laptop']);

        // The token must actually authenticate a Bearer-protected route — this
        // is the behaviour the endpoint exists for, not just a 201 shape.
        $token = $response->json('token');
        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_device_name_defaults_when_omitted(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/token', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertCreated();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'desktop-demo']);
    }

    public function test_wrong_password_returns_422_and_mints_no_token(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/token', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_email_returns_422_and_mints_no_token(): void
    {
        $this->postJson('/api/auth/token', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_missing_fields_return_422(): void
    {
        $this->postJson('/api/auth/token', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_throttled_after_five_failed_attempts(): void
    {
        $this->makeUser();

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/auth/token', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/token', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_logout_revokes_the_bearer_token(): void
    {
        $user = $this->makeUser();

        $token = $this->postJson('/api/auth/token', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->json('token');

        // The token works...
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        // ...logout revokes it...
        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Drop the in-memory guard cache so the next request truly re-resolves
        // the token against the (now empty) table instead of the user Sanctum
        // cached on the guard during the previous request in this same test.
        $this->app['auth']->forgetGuards();

        // ...and the same token is now rejected.
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }
}
