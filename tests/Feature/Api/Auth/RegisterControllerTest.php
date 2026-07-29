<?php

namespace Tests\Feature\Api\Auth;

use App\Invitations\RegistrationInvitationIssuer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
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

        // Reset rate-limiter + Spatie permission caches between tests.
        Cache::flush();
        // The controller floors every new account at 'viewer'; the role must
        // exist (RbacSeeder seeds it in production).
        Role::findOrCreate('viewer', 'web');
    }

    private function mintCode(): string
    {
        return app(RegistrationInvitationIssuer::class)
            ->issueCompanyBootstrap(maxUses: 5)
            ->code;
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
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'abilities', 'registration'])
            ->assertJsonPath('user.email', 'new@example.com')
            ->assertJsonPath('registration.intent', 'company_bootstrap')
            ->assertJsonPath('registration.onboarding_required', true);

        $this->assertTrue(Auth::check(), 'A successful registration opens the session.');
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('viewer'), 'New accounts are floored at the viewer role.');
        $this->assertDatabaseMissing('project_memberships', ['user_id' => $user->id]);

        // The code was actually consumed (redemption ran), not merely validated.
        $this->assertDatabaseHas('invite_codes', [
            'code' => $code,
            'current_uses' => 1,
        ]);
        $this->assertNotNull($user->fresh()->registration_completed_at);
    }

    public function test_tenant_linked_code_provisions_membership_and_skips_onboarding(): void
    {
        Tenant::create([
            'slug' => 'acme',
            'name' => 'Acme',
            'status' => 'active',
            'is_system' => false,
        ]);
        Project::create([
            'tenant_id' => 'acme',
            'project_key' => 'acme-kb',
            'name' => 'Acme KB',
        ]);
        $code = app(RegistrationInvitationIssuer::class)->issueTenantJoin(
            'acme',
            ['acme-kb'],
            'viewer',
            'member',
        )->code;

        $response = $this->postJson('/api/auth/register', $this->payload([
            'invite_code' => $code,
        ]));

        $response->assertCreated()
            ->assertJsonPath('registration.intent', 'tenant_join')
            ->assertJsonPath('registration.target_tenant', 'acme')
            ->assertJsonPath('registration.onboarding_required', false);

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'member',
        ]);
        $this->assertNotNull($user->fresh()->registration_completed_at);
    }

    public function test_login_recovers_a_consumed_tenant_invite_after_membership_provisioning_failed(): void
    {
        Tenant::create([
            'slug' => 'acme',
            'name' => 'Acme',
            'status' => 'active',
            'is_system' => false,
        ]);
        Project::create([
            'tenant_id' => 'acme',
            'project_key' => 'acme-kb',
            'name' => 'Acme KB',
        ]);
        $code = app(RegistrationInvitationIssuer::class)->issueTenantJoin(
            'acme',
            ['acme-kb'],
            'viewer',
            'member',
        )->code;

        // The package provisioner is best-effort, so force both it and the
        // host's strict completion layer through a real database failure.
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_registration_membership
            BEFORE INSERT ON project_memberships
            WHEN NEW.tenant_id = 'acme'
            BEGIN
                SELECT RAISE(ABORT, 'forced membership provisioning failure');
            END
        SQL);

        $this->postJson('/api/auth/register', $this->payload([
            'invite_code' => $code,
        ]))
            ->assertStatus(503)
            ->assertJsonPath('error', 'registration_provisioning_pending');

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertFalse(Auth::check(), 'Incomplete registration must not open a session.');
        $this->assertNull($user->registration_completed_at);
        $this->assertDatabaseHas('invite_codes', ['code' => $code, 'current_uses' => 1]);
        $this->assertDatabaseMissing('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
        ]);

        DB::statement('DROP TRIGGER fail_registration_membership');

        // The consumed redemption is the durable recovery anchor: no second
        // code is needed and the next valid login completes the same grant.
        $this->postJson('/api/auth/login', [
            'email' => 'new@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $this->assertTrue(Auth::check());
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'member',
        ]);
        $this->assertNotNull($user->fresh()->registration_completed_at);

        // Once completion is marked, a later deliberate removal is not
        // mistaken for an interrupted registration and is never auto-revoked.
        $user->projectMemberships()->delete();
        Auth::guard('web')->logout();

        $this->postJson('/api/auth/login', [
            'email' => 'new@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $this->assertDatabaseMissing('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
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

    public function test_register_is_throttled_after_six_attempts_per_ip(): void
    {
        $payload = [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'invite_code' => 'XXXXXXXX',
        ];

        foreach (range(1, 6) as $i) {
            $this->postJson('/api/auth/register', $payload)->assertStatus(422);
        }

        $this->postJson('/api/auth/register', $payload)->assertStatus(429);
    }
}
