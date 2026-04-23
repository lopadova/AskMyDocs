<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PR2 ships only the stub — AUTH_2FA_ENABLED defaults to false so every
 * endpoint returns 501 until a later PR wires the real TOTP flow.
 */
class TwoFactorStubTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.two_factor.enabled', false);
    }

    private function authedUser(): User
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->actingAs($user);

        return $user;
    }

    public function test_enable_returns_501_when_disabled(): void
    {
        $this->authedUser();

        $this->postJson('/api/auth/2fa/enable')
            ->assertStatus(501)
            ->assertJson(['message' => 'Two-factor authentication is not yet available.']);
    }

    public function test_verify_returns_501_when_disabled(): void
    {
        $this->authedUser();

        $this->postJson('/api/auth/2fa/verify', ['code' => '123456'])
            ->assertStatus(501)
            ->assertJson(['message' => 'Two-factor authentication is not yet available.']);
    }

    public function test_disable_returns_501_when_disabled(): void
    {
        $this->authedUser();

        $this->postJson('/api/auth/2fa/disable')
            ->assertStatus(501)
            ->assertJson(['message' => 'Two-factor authentication is not yet available.']);
    }

    public function test_unauthenticated_2fa_endpoint_returns_401(): void
    {
        $this->postJson('/api/auth/2fa/enable')
            ->assertStatus(401);
    }
}
