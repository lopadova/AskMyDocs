<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.two_factor.enabled', true);
    }

    public function test_enable_returns_one_time_provisioning_secret_without_enabling_account(): void
    {
        $user = $this->authedUser();

        $response = $this->postJson('/api/auth/2fa/enable');

        $response->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('provisioning_uri', fn (mixed $uri): bool => is_string($uri) && str_starts_with($uri, 'otpauth://totp/'))
            ->assertJsonPath('secret', fn (mixed $secret): bool => is_string($secret) && preg_match('/^[A-Z2-7]{32}$/', $secret) === 1);

        $this->assertNull($user->fresh()->two_factor_enabled_at);
        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    public function test_valid_totp_confirms_enrollment_and_returns_recovery_codes_once(): void
    {
        $this->authedUser();
        $secret = (string) $this->postJson('/api/auth/2fa/enable')->json('secret');

        $response = $this->postJson('/api/auth/2fa/verify', [
            'code' => $this->totp($secret),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'enabled')
            ->assertJsonCount(10, 'recovery_codes');

        $this->assertNotNull(User::query()->firstOrFail()->fresh()->two_factor_enabled_at);
        $this->assertArrayNotHasKey('secret', $response->json());

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('user.two_factor_secret');
    }

    public function test_wrong_code_does_not_enable_two_factor(): void
    {
        $user = $this->authedUser();
        $this->postJson('/api/auth/2fa/enable')->assertOk();

        $this->postJson('/api/auth/2fa/verify', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The provided two-factor code is invalid.');

        $this->assertNull($user->fresh()->two_factor_enabled_at);
    }

    public function test_recovery_code_is_single_use(): void
    {
        $this->authedUser();
        $secret = (string) $this->postJson('/api/auth/2fa/enable')->json('secret');
        $recoveryCode = (string) $this->postJson('/api/auth/2fa/verify', ['code' => $this->totp($secret)])->json('recovery_codes.0');

        // A recovery code is an authenticator fallback, not a reusable password.
        $this->postJson('/api/auth/2fa/verify', ['code' => $recoveryCode])
            ->assertOk()
            ->assertJson(['status' => 'verified']);
        $this->postJson('/api/auth/2fa/verify', ['code' => $recoveryCode])
            ->assertStatus(422);
    }

    public function test_disable_requires_a_valid_code_and_removes_secret(): void
    {
        $this->authedUser();
        $secret = (string) $this->postJson('/api/auth/2fa/enable')->json('secret');
        $recoveryCode = (string) $this->postJson('/api/auth/2fa/verify', ['code' => $this->totp($secret)])->json('recovery_codes.0');

        $this->postJson('/api/auth/2fa/disable', ['code' => '000000'])
            ->assertStatus(422);

        $this->postJson('/api/auth/2fa/disable', ['code' => $recoveryCode])
            ->assertOk()
            ->assertJson(['status' => 'disabled']);

        $this->assertNull(User::query()->firstOrFail()->fresh()->two_factor_secret);
    }

    public function test_verify_is_rate_limited_per_identity_and_ip(): void
    {
        $this->authedUser();
        config()->set('auth.two_factor.rate_limit_per_minute', 1);
        $this->postJson('/api/auth/2fa/enable')->assertOk();

        $this->postJson('/api/auth/2fa/verify', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/api/auth/2fa/verify', ['code' => '000000'])->assertStatus(429);
    }

    private function totp(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin(strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $octet) {
            if (strlen($octet) === 8) {
                $binary .= chr(bindec($octet));
            }
        }

        $counter = pack('N*', 0).pack('N*', intdiv(time(), 30));
        $hash = hash_hmac('sha1', $counter, $binary, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
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
}
