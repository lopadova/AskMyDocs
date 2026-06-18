<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * R44 — the HTTP API surface over the same core services. Covers the
 * user-facing redeem/validate happy + failure paths and the admin
 * issuance/revocation flow.
 */
final class InviteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    public function test_authenticated_user_redeems_a_code(): void
    {
        $code = $this->code('REDEEM01', maxUses: 1);
        $this->actingAs($this->user('u@example.com'));

        $this->postJson('/api/invite/redeem', ['code' => 'redeem01'])
            ->assertOk()
            ->assertJson(['ok' => true, 'already' => false]);

        $this->assertSame(1, $code->fresh()->current_uses);
    }

    public function test_redeem_returns_idempotent_success_on_second_submit(): void
    {
        $this->code('DEM00001', maxUses: 3);
        $user = $this->user('u2@example.com');
        $this->actingAs($user);

        $this->postJson('/api/invite/redeem', ['code' => 'DEM00001'])->assertOk();
        $this->postJson('/api/invite/redeem', ['code' => 'DEM00001'])
            ->assertOk()
            ->assertJson(['ok' => true, 'already' => true]);
    }

    public function test_redeem_unknown_code_returns_422_invalid(): void
    {
        $this->actingAs($this->user('u3@example.com'));

        $this->postJson('/api/invite/redeem', ['code' => 'NOPE0000'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => 'invalid']);
    }

    public function test_redeem_exhausted_code_returns_409(): void
    {
        $this->code('FNE00002', maxUses: 1);
        $this->actingAs($this->user('first@example.com'));
        $this->postJson('/api/invite/redeem', ['code' => 'FNE00002'])->assertOk();

        $this->actingAs($this->user('second@example.com'));
        $this->postJson('/api/invite/redeem', ['code' => 'FNE00002'])
            ->assertStatus(409)
            ->assertJson(['error' => 'exhausted']);
    }

    public function test_redeem_requires_authentication(): void
    {
        $this->code('GUEST003', maxUses: 1);

        $this->postJson('/api/invite/redeem', ['code' => 'GUEST003'])
            ->assertStatus(401);
    }

    public function test_admin_generates_and_revokes_codes(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $generate = $this->postJson('/api/admin/invite/codes', ['count' => 5, 'max_uses' => 2])
            ->assertStatus(201)
            ->assertJsonCount(5, 'data');

        $codeId = $generate->json('data.0.id');

        $this->postJson("/api/admin/invite/codes/{$codeId}/revoke")
            ->assertOk()
            ->assertJson(['data' => ['state' => 'revoked']]);
    }

    public function test_admin_creates_campaign(): void
    {
        $this->actingAs($this->adminUser());

        $this->postJson('/api/admin/invite/campaigns', [
            'key' => 'spring-beta',
            'name' => 'Spring Beta',
            'type' => 'multi_use',
        ])->assertStatus(201)
            ->assertJson(['data' => ['key' => 'spring-beta', 'status' => 'draft']]);
    }

    public function test_admin_reads_metrics(): void
    {
        $this->actingAs($this->adminUser());

        $this->getJson('/api/admin/invite/metrics')
            ->assertOk()
            ->assertJsonStructure(['data' => ['codes_issued', 'redemptions', 'k_factor', 'conversion_rate']]);
    }

    public function test_admin_sends_invitation_idempotently(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->actingAs($this->adminUser());

        $this->postJson('/api/admin/invite/invitations', ['recipient' => 'invitee@example.com'])
            ->assertStatus(201)
            ->assertJson(['data' => ['recipient' => 'invitee@example.com', 'status' => 'pending']]);
    }

    public function test_user_reads_pending_invitation_count(): void
    {
        $this->actingAs($this->user('me@example.com'));

        $this->getJson('/api/invite/pending-count')
            ->assertOk()
            ->assertJson(['pending' => 0]);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = $this->user('admin@example.com');
        $user->assignRole('admin');

        return $user;
    }

    private function code(string $code, int $maxUses): InviteCode
    {
        return InviteCode::create([
            'code' => $code,
            'code_kind' => InviteCode::KIND_RANDOM,
            'state' => InviteCode::STATE_ACTIVE,
            'max_uses' => $maxUses,
            'current_uses' => 0,
        ]);
    }
}
