<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Padosoft\Invitations\Services\CodeGenerator;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * POST /api/auth/register-token — invite-only Bearer sign-up for the Tauri
 * desktop app. The stateless counterpart of POST /api/auth/register: it creates
 * the account + redeems the code through the SAME core, then returns a
 * least-privilege desktop token instead of opening a session.
 *
 * Mounts the real routes/api.php (like TokenTest / RegisterControllerTest) so
 * the full stateless stack runs.
 */
class RegisterTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set('default');
        Cache::flush();
        Role::findOrCreate('viewer', 'web');
    }

    private function mintCode(): string
    {
        return app(CodeGenerator::class)->generateRandom(['max_uses' => 5])->code;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Desktop User',
            'email' => 'desktop@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'invite_code' => $this->mintCode(),
            'device_name' => 'AskMyDocs Desktop',
        ], $overrides);
    }

    public function test_valid_code_returns_201_with_a_usable_desktop_bearer_token(): void
    {
        $code = $this->mintCode();

        $response = $this->postJson('/api/auth/register-token', $this->payload(['invite_code' => $code]));

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'desktop@example.com');

        // Stateless — no web session is opened by this endpoint.
        $this->assertFalse(Auth::check());

        $user = User::where('email', 'desktop@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('viewer'));
        $this->assertDatabaseHas('invite_codes', ['code' => $code, 'current_uses' => 1]);

        // Same least-privilege contract as POST /api/auth/token (DesktopToken).
        $pat = PersonalAccessToken::firstOrFail();
        $this->assertEqualsCanonicalizing(['kb:read', 'kb:chat'], $pat->abilities);
        $this->assertNotNull($pat->expires_at);
        $this->assertTrue($pat->expires_at->isFuture());
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'AskMyDocs Desktop']);

        // The token must actually authenticate a Bearer-protected route.
        $this->withToken($response->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_device_name_defaults_when_null_is_sent(): void
    {
        $this->postJson('/api/auth/register-token', $this->payload(['device_name' => null]))
            ->assertCreated();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'desktop-demo']);
    }

    public function test_register_token_requires_an_invite_code(): void
    {
        $response = $this->postJson('/api/auth/register-token', $this->payload(['invite_code' => '']));

        $response->assertStatus(422)->assertJsonValidationErrors(['invite_code']);
        $this->assertDatabaseMissing('users', ['email' => 'desktop@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_register_token_rejects_an_unknown_code_and_mints_nothing(): void
    {
        $response = $this->postJson('/api/auth/register-token', $this->payload(['invite_code' => 'XXXXXXXX']));

        $response->assertStatus(422)->assertJsonValidationErrors(['invite_code']);
        $this->assertDatabaseMissing('users', ['email' => 'desktop@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_register_token_rejects_a_duplicate_email(): void
    {
        User::create([
            'name' => 'Existing',
            'email' => 'desktop@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/auth/register-token', $this->payload());

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_register_token_force_deletes_the_account_and_mints_no_token_when_redeem_is_blocked(): void
    {
        // Valid code → pre-check passes → account created → redeem() anti-abuse
        // gate blocks a blocklisted email → rollback. No user, no token survive.
        $code = $this->mintCode();
        config()->set('invitations.anti_abuse.enabled', true);
        config()->set('invitations.anti_abuse.blocklist.emails', ['racer@example.com']);

        $response = $this->postJson('/api/auth/register-token', $this->payload([
            'email' => 'racer@example.com',
            'invite_code' => $code,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['invite_code']);
        $this->assertDatabaseMissing('users', ['email' => 'racer@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('invite_codes', ['code' => $code, 'current_uses' => 0]);
    }
}
