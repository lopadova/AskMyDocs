<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    private function makeUser(string $password = 'old-password-123'): User
    {
        return User::create([
            'name' => 'Test',
            'email' => 'known@example.com',
            'password' => Hash::make($password),
        ]);
    }

    public function test_valid_token_resets_password_and_returns_204(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertNoContent(204);

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
    }

    public function test_invalid_token_returns_422(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => 'definitely-not-a-real-token',
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);

        $user->refresh();
        $this->assertFalse(Hash::check('new-password-123', $user->password));
    }

    public function test_password_confirmation_mismatch_returns_422(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'something-else',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_password_too_short_returns_422(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }
}
