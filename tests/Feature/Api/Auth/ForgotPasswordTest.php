<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    public function test_existing_user_receives_reset_notification_and_204(): void
    {
        Notification::fake();

        $user = User::create([
            'name' => 'Test',
            'email' => 'known@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertNoContent(204);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_still_returns_204_anti_enumeration(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'ghost@example.com',
        ]);

        $response->assertNoContent(204);
        Notification::assertNothingSent();
    }

    public function test_invalid_email_payload_returns_422(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_is_throttled_after_three_attempts(): void
    {
        Notification::fake();

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/auth/forgot-password', [
                'email' => 'known@example.com',
            ])->assertNoContent(204);
        }

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'known@example.com',
        ]);

        $response->assertStatus(429);
    }
}
