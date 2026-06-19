<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Models\InviteCampaign;
use App\Models\InviteCode;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Invite\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The invite key carries a provisioning grant — the role the redeemer is
 * granted and the tenant projects they gain access to — applied on a fresh
 * redemption (GRANT-never-REVOKE, best-effort).
 */
final class InviteGrantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    public function test_grant_columns_exist_on_campaigns_and_codes(): void
    {
        $this->assertTrue(Schema::hasColumn('invite_campaigns', 'grant'));
        $this->assertTrue(Schema::hasColumn('invite_codes', 'grant'));
    }

    public function test_campaign_grant_provisions_role_and_project_membership(): void
    {
        Role::findOrCreate('editor', 'web');

        $campaign = $this->campaign(['role' => 'editor', 'projects' => ['docs', 'wiki']]);
        $code = $this->code('GRANT001', $campaign);
        $user = $this->user('redeemer@example.com');

        $result = $this->redeem($code->code, $user);

        $this->assertTrue($result->ok);
        $this->assertTrue($user->fresh()->hasRole('editor'));

        $memberships = ProjectMembership::query()
            ->forTenant('default')
            ->where('user_id', $user->id)
            ->pluck('role', 'project_key');

        $this->assertSame(['docs' => 'member', 'wiki' => 'member'], $memberships->all());
    }

    public function test_code_grant_overrides_campaign_grant(): void
    {
        Role::findOrCreate('editor', 'web');
        Role::findOrCreate('viewer', 'web');

        $campaign = $this->campaign(['role' => 'editor', 'projects' => ['docs']]);
        $code = $this->code('GRANT002', $campaign, ['role' => 'viewer', 'projects' => ['ops']]);
        $user = $this->user('override@example.com');

        $this->redeem($code->code, $user);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('viewer'));
        $this->assertFalse($fresh->hasRole('editor'));
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'ops',
        ]);
        $this->assertDatabaseMissing('project_memberships', [
            'user_id' => $user->id,
            'project_key' => 'docs',
        ]);
    }

    public function test_empty_grant_provisions_nothing(): void
    {
        $campaign = $this->campaign(null);
        $code = $this->code('GRANT003', $campaign);
        $user = $this->user('nogrant@example.com');

        $this->redeem($code->code, $user);

        $this->assertSame([], $user->fresh()->getRoleNames()->all());
        $this->assertDatabaseCount('project_memberships', 0);
    }

    public function test_provisioning_never_downgrades_existing_role_or_membership(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('viewer', 'web');

        $user = $this->user('existing@example.com');
        $user->assignRole('admin');
        ProjectMembership::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'docs',
            'role' => 'owner',
        ]);

        $campaign = $this->campaign(['role' => 'viewer', 'projects' => ['docs'], 'project_role' => 'member']);
        $code = $this->code('GRANT004', $campaign);

        $this->redeem($code->code, $user);

        $fresh = $user->fresh();
        // Additive: keeps admin AND gains viewer — never a downgrade.
        $this->assertTrue($fresh->hasRole('admin'));
        $this->assertTrue($fresh->hasRole('viewer'));
        // firstOrCreate: the pre-existing owner membership is untouched.
        $this->assertDatabaseHas('project_memberships', [
            'user_id' => $user->id,
            'project_key' => 'docs',
            'role' => 'owner',
        ]);
        $this->assertSame(
            1,
            ProjectMembership::where('user_id', $user->id)->where('project_key', 'docs')->count(),
        );
    }

    public function test_idempotent_replay_does_not_duplicate_membership(): void
    {
        Role::findOrCreate('editor', 'web');

        $campaign = $this->campaign(['role' => 'editor', 'projects' => ['docs']]);
        $code = $this->code('GRANT005', $campaign, null, maxUses: 3);
        $user = $this->user('replay@example.com');

        $first = $this->redeem($code->code, $user);
        $second = $this->redeem($code->code, $user);

        $this->assertFalse($first->already);
        $this->assertTrue($second->already);
        $this->assertSame(
            1,
            ProjectMembership::where('user_id', $user->id)->where('project_key', 'docs')->count(),
        );
        // Replay never re-claims a seat.
        $this->assertSame(1, $code->fresh()->current_uses);
    }

    public function test_campaign_create_rejects_super_admin_grant_role(): void
    {
        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('admin', 'web');
        $admin = $this->user('boss@example.com');
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->postJson('/api/admin/invite/campaigns', [
            'key' => 'priv-esc',
            'name' => 'Priv Esc',
            'type' => InviteCampaign::TYPE_MULTI_USE,
            'grant' => ['role' => 'super-admin', 'projects' => ['docs']],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('grant.role');
    }

    public function test_multi_tenant_grant_provisions_memberships_across_tenants(): void
    {
        Role::findOrCreate('editor', 'web');
        Role::findOrCreate('viewer', 'web');

        // One code, two tenants ("teams"): the redeemer is provisioned in BOTH.
        $campaign = $this->campaign(['tenants' => [
            ['tenant_id' => 'default', 'role' => 'editor', 'projects' => ['docs']],
            ['tenant_id' => 'acme', 'role' => 'viewer', 'projects' => ['eng', 'ops'], 'project_role' => 'admin'],
        ]]);
        $code = $this->code('GRANT006', $campaign);
        $user = $this->user('multi@example.com');

        $result = $this->redeem($code->code, $user);

        $this->assertTrue($result->ok);

        $fresh = $user->fresh();
        // Roles are global (Spatie) — additive across tenant grants.
        $this->assertTrue($fresh->hasRole('editor'));
        $this->assertTrue($fresh->hasRole('viewer'));

        // Membership in the FIRST tenant.
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'docs',
            'role' => 'member',
        ]);

        // Membership in the SECOND tenant — written even though the redemption
        // ran under 'default' (the grant's explicit tenant_id wins).
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'eng',
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'ops',
            'role' => 'admin',
        ]);

        $this->assertSame(3, ProjectMembership::where('user_id', $user->id)->count());
    }

    public function test_campaign_create_rejects_super_admin_in_a_tenant_grant(): void
    {
        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('admin', 'web');
        $admin = $this->user('boss2@example.com');
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->postJson('/api/admin/invite/campaigns', [
            'key' => 'priv-esc-tenant',
            'name' => 'Priv Esc Tenant',
            'type' => InviteCampaign::TYPE_MULTI_USE,
            'grant' => ['tenants' => [
                ['tenant_id' => 'acme', 'role' => 'super-admin', 'projects' => ['eng']],
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('grant.tenants.0.role');
    }

    /**
     * @param  array<string, mixed>|null  $grant
     */
    private function campaign(?array $grant): InviteCampaign
    {
        $creator = $this->user('creator-'.uniqid().'@example.com');

        return InviteCampaign::create([
            'key' => 'camp-'.uniqid(),
            'name' => 'Campaign',
            'type' => InviteCampaign::TYPE_MULTI_USE,
            'status' => InviteCampaign::STATUS_ACTIVE,
            'grant' => $grant,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $grant
     */
    private function code(string $code, InviteCampaign $campaign, ?array $grant = null, int $maxUses = 1): InviteCode
    {
        return InviteCode::create([
            'campaign_id' => $campaign->id,
            'code' => $code,
            'code_kind' => InviteCode::KIND_RANDOM,
            'state' => InviteCode::STATE_ACTIVE,
            'max_uses' => $maxUses,
            'current_uses' => 0,
            'grant' => $grant,
        ]);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }

    private function redeem(string $code, User $user): \App\Services\Invite\Support\RedemptionResult
    {
        return app(RedemptionService::class)->redeem($code, $user);
    }
}
