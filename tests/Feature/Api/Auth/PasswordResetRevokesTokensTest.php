<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * A password reset must revoke every issued API token, and issued tokens must
 * carry a bounded lifetime (SEC-TOKEN-001 / dormant-access).
 */
class PasswordResetRevokesTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Token User',
            'email' => 'token-user@example.com',
            'password' => Hash::make('old-password-123'),
        ]);
    }

    public function test_reset_revokes_all_issued_api_tokens(): void
    {
        $user = $this->makeUser();
        $user->createToken('desktop');
        $user->createToken('cli');

        $this->assertSame(2, $user->tokens()->count());

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertNoContent(204);

        $this->assertSame(0, $user->tokens()->count(), 'password reset must revoke all API tokens');
    }

    public function test_sanctum_token_expiration_is_bounded_not_null(): void
    {
        $expiration = config('sanctum.expiration');

        $this->assertNotNull($expiration, 'sanctum token expiration must be bounded, never null');
        $this->assertIsInt($expiration);
        $this->assertGreaterThan(0, $expiration);
    }
}
