<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Auth;

use App\Models\InviteCampaign;
use App\Models\InviteCode;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Public, invite-gated self-registration (POST /api/auth/register).
 *
 * The invite code is a hard constraint when invite.invitation_required is on:
 * no code → 422, no account. A valid code creates + signs in the user AND
 * provisions the account from the key's grant (role + projects). A bad / spent
 * code never leaves an orphan user behind.
 */
final class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    public function test_registration_requires_an_invite_code_when_the_gate_is_on(): void
    {
        config()->set('invite.invitation_required', true);

        $this->postJson('/api/auth/register', [
            'name' => 'No Code',
            'email' => 'nocode@example.com',
            'password' => 'Sup3r-secret!',
            'password_confirmation' => 'Sup3r-secret!',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertDatabaseMissing('users', ['email' => 'nocode@example.com']);
        $this->assertFalse(Auth::check());
    }

    public function test_valid_code_registers_signs_in_and_provisions_the_account(): void
    {
        config()->set('invite.invitation_required', true);
        Role::findOrCreate('editor', 'web');

        $campaign = $this->campaign(['role' => 'editor', 'projects' => ['docs']]);
        $this->code('GETSEAT5', $campaign, maxUses: 5);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jane Invitee',
            'email' => 'jane@example.com',
            'password' => 'Sup3r-secret!',
            'password_confirmation' => 'Sup3r-secret!',
            'code' => 'getseat5',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['user' => ['id', 'name', 'email']]);

        $this->assertTrue(Auth::check());
        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('editor'));
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'docs',
        ]);
    }

    public function test_multi_tenant_code_lands_the_user_with_teams_visible_in_me(): void
    {
        // Capstone: register via an invite whose grant spans TWO tenants, then
        // confirm /api/auth/me surfaces both as teams (with their projects +
        // the granted roles) — exactly what the desktop client renders.
        config()->set('invite.invitation_required', true);
        Role::findOrCreate('editor', 'web');
        Role::findOrCreate('viewer', 'web');

        $campaign = $this->campaign(['tenants' => [
            ['tenant_id' => 'default', 'role' => 'editor', 'projects' => ['docs']],
            ['tenant_id' => 'acme', 'role' => 'viewer', 'projects' => ['eng'], 'project_role' => 'admin'],
        ]]);
        $this->code('GETSEAT6', $campaign, maxUses: 5);

        $this->postJson('/api/auth/register', [
            'name' => 'Multi Tenant',
            'email' => 'multi-reg@example.com',
            'password' => 'Sup3r-secret!',
            'password_confirmation' => 'Sup3r-secret!',
            'code' => 'GETSEAT6',
        ])->assertStatus(201);

        // The register flow signed the user in (web session) → me() resolves it.
        $me = $this->getJson('/api/auth/me')->assertOk()->json();

        $this->assertContains('editor', $me['roles']);
        $this->assertContains('viewer', $me['roles']);

        $teams = collect($me['teams'])->keyBy('tenant_id');
        $this->assertTrue($teams->has('default'));
        $this->assertTrue($teams->has('acme'));

        $defaultProjects = collect($teams['default']['projects'])->pluck('project_key')->all();
        $acmeProjects = collect($teams['acme']['projects'])->pluck('project_key')->all();
        $this->assertContains('docs', $defaultProjects);
        $this->assertContains('eng', $acmeProjects);
        $this->assertSame(
            'admin',
            collect($teams['acme']['projects'])->firstWhere('project_key', 'eng')['role'],
        );
    }

    public function test_invalid_code_returns_422_and_creates_no_user(): void
    {
        config()->set('invite.invitation_required', true);

        $this->postJson('/api/auth/register', [
            'name' => 'Bad Code',
            'email' => 'badcode@example.com',
            'password' => 'Sup3r-secret!',
            'password_confirmation' => 'Sup3r-secret!',
            'code' => 'NOPE0000',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertDatabaseMissing('users', ['email' => 'badcode@example.com']);
        $this->assertFalse(Auth::check());
    }

    public function test_exhausted_single_use_code_rolls_back_and_leaves_no_orphan_user(): void
    {
        config()->set('invite.invitation_required', true);

        $campaign = $this->campaign(null);
        $this->code('SEAT0001', $campaign, maxUses: 1);

        // First registration consumes the only seat.
        $this->postJson('/api/auth/register', [
            'name' => 'First',
            'email' => 'first@example.com',
            'password' => 'Sup3r-secret!',
            'password_confirmation' => 'Sup3r-secret!',
            'code' => 'SEAT0001',
        ])->assertStatus(201);

        // Second registration on the spent code → redemption failure → 409,
        // and crucially NO orphan user row.
        $this->postJson('/api/auth/register', [
            'name' => 'Second',
            'email' => 'second@example.com',
            'password' => 'Sup3r-secret!',
            'password_confirmation' => 'Sup3r-secret!',
            'code' => 'SEAT0001',
        ])->assertStatus(409);

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_registration_without_a_code_succeeds_when_the_gate_is_off(): void
    {
        config()->set('invite.invitation_required', false);

        $this->postJson('/api/auth/register', [
            'name' => 'Open Signup',
            'email' => 'open@example.com',
            'password' => 'Sup3r-secret!',
            'password_confirmation' => 'Sup3r-secret!',
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'open@example.com']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        config()->set('invite.invitation_required', false);
        User::create([
            'name' => 'Existing',
            'email' => 'dupe@example.com',
            'password' => bcrypt('whatever1'),
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Clash',
            'email' => 'dupe@example.com',
            'password' => 'Sup3r-secret!',
            'password_confirmation' => 'Sup3r-secret!',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /**
     * @param  array<string, mixed>|null  $grant
     */
    private function campaign(?array $grant): InviteCampaign
    {
        $creator = User::create([
            'name' => 'Creator',
            'email' => 'creator-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'),
        ]);

        return InviteCampaign::create([
            'key' => 'camp-'.uniqid(),
            'name' => 'Campaign',
            'type' => InviteCampaign::TYPE_MULTI_USE,
            'status' => InviteCampaign::STATUS_ACTIVE,
            'grant' => $grant,
            'created_by' => $creator->id,
        ]);
    }

    private function code(string $code, InviteCampaign $campaign, int $maxUses = 1): InviteCode
    {
        return InviteCode::create([
            'campaign_id' => $campaign->id,
            'code' => $code,
            'code_kind' => InviteCode::KIND_RANDOM,
            'state' => InviteCode::STATE_ACTIVE,
            'max_uses' => $maxUses,
            'current_uses' => 0,
        ]);
    }
}
