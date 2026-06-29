<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\Invitations\Services\CodeGenerator;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature coverage for POST /api/auth/register — invite-only sign-up.
 *
 * Mounts the real routes/api.php (same approach as LoginTest) so the full
 * stack runs: api + web → Sanctum stateful → throttle:register. Codes are
 * minted with the package CodeGenerator; InviteCode persists the normalized
 * plaintext in its `code` column, so $code->code is the value a user types.
 */
class RegisterControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic tenant: codes are minted in — and validated against —
        // the same active tenant.
        app(TenantContext::class)->set('default');
        // Reset rate-limiter + Spatie permission caches between tests.
        Cache::flush();
        // The controller floors every new account at 'viewer'; the role must
        // exist (RbacSeeder seeds it in production).
        Role::findOrCreate('viewer', 'web');
    }

    private function mintCode(): string
    {
        return app(CodeGenerator::class)->generateRandom(['max_uses' => 5])->code;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'invite_code' => $this->mintCode(),
        ], $overrides);
    }

    public function test_register_with_a_valid_code_creates_the_user_logs_in_and_redeems(): void
    {
        $code = $this->mintCode();

        $response = $this->postJson('/api/auth/register', $this->payload(['invite_code' => $code]));

        $response->assertStatus(201)
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'abilities'])
            ->assertJsonPath('user.email', 'new@example.com');

        $this->assertTrue(Auth::check(), 'A successful registration opens the session.');
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('viewer'), 'New accounts are floored at the viewer role.');

        // The code was actually consumed (redemption ran), not merely validated.
        $this->assertDatabaseHas('invite_codes', [
            'code' => $code,
            'current_uses' => 1,
        ]);
    }

    public function test_register_requires_an_invite_code(): void
    {
        $response = $this->postJson('/api/auth/register', $this->payload(['invite_code' => '']));

        $response->assertStatus(422)->assertJsonValidationErrors(['invite_code']);
        $this->assertFalse(Auth::check());
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_register_rejects_an_unknown_code_without_creating_the_user(): void
    {
        // Well-formed (alphabet-valid) but not present in the DB → Invalid.
        $response = $this->postJson('/api/auth/register', $this->payload(['invite_code' => 'XXXXXXXX']));

        $response->assertStatus(422)->assertJsonValidationErrors(['invite_code']);
        $this->assertFalse(Auth::check());
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        User::create([
            'name' => 'Existing',
            'email' => 'new@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->postJson('/api/auth/register', $this->payload());

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertFalse(Auth::check());
    }

    public function test_register_rejects_a_password_confirmation_mismatch(): void
    {
        $response = $this->postJson('/api/auth/register', $this->payload([
            'password' => 'secret123',
            'password_confirmation' => 'different456',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertFalse(Auth::check());
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_register_force_deletes_the_account_when_redeem_is_blocked_after_validation(): void
    {
        // Drive the post-create rollback deterministically WITHOUT mocking the
        // final RedemptionService: the code is genuinely valid (CodeValidator
        // pre-check passes → the account IS created), but redeem() runs the
        // anti-abuse gate that validate() does NOT. A blocklisted email scores a
        // hard BLOCK → redeem returns RateLimited, exactly the post-validation
        // failure shape the rare exhausted-between-checks race would produce.
        $code = $this->mintCode();
        config()->set('invitations.anti_abuse.enabled', true);
        config()->set('invitations.anti_abuse.blocklist.emails', ['racer@example.com']);

        $response = $this->postJson('/api/auth/register', $this->payload([
            'email' => 'racer@example.com',
            'invite_code' => $code,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['invite_code']);
        $this->assertFalse(Auth::check());
        // The brand-new account must NOT survive a failed redeem — invite-only
        // invariant: no account that consumed no code...
        $this->assertDatabaseMissing('users', ['email' => 'racer@example.com']);
        // ...and the seat was never claimed (block fires before claimSeat).
        $this->assertDatabaseHas('invite_codes', ['code' => $code, 'current_uses' => 0]);
    }
}
