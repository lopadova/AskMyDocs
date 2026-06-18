<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Models\InviteCampaign;
use App\Models\InviteCode;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Invite\RedemptionService;
use App\Services\Invite\Support\RedemptionError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 2 DoD — atomic, idempotent redemption.
 *
 * SQLite cannot exercise true parallelism, so "exactly K of N succeed" is
 * asserted by firing N sequential claims by distinct accounts and proving
 * current_uses never exceeds max_uses and exactly K win. The true concurrent
 * race is the Phase 6 pgsql concurrency test.
 */
final class RedemptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RedemptionService
    {
        return app(RedemptionService::class);
    }

    public function test_single_use_code_transitions_to_redeemed(): void
    {
        $code = $this->code('ABCDEF12', maxUses: 1);
        $user = $this->user('a@example.com');

        $result = $this->service()->redeem('abcdef12', $user);

        $this->assertTrue($result->ok);
        $this->assertFalse($result->already);
        $code->refresh();
        $this->assertSame(1, $code->current_uses);
        $this->assertSame(InviteCode::STATE_REDEEMED, $code->state);
    }

    public function test_idempotent_reredeem_returns_same_redemption_no_second_increment(): void
    {
        $code = $this->code('DEMP1234', maxUses: 5);
        $user = $this->user('b@example.com');

        $first = $this->service()->redeem('DEMP1234', $user);
        $second = $this->service()->redeem('demp1234', $user);

        $this->assertTrue($first->ok);
        $this->assertTrue($second->ok);
        $this->assertTrue($second->already);
        $this->assertSame($first->redemption->id, $second->redemption->id);

        $code->refresh();
        $this->assertSame(1, $code->current_uses, 'No second increment');
        $this->assertSame(1, Redemption::where('code_id', $code->id)->count());
    }

    public function test_multi_use_code_exhausts_on_kth_claim_and_never_over_increments(): void
    {
        $k = 3;
        $code = $this->code('CAP00003', maxUses: $k);

        $wins = 0;
        for ($i = 0; $i < $k + 2; $i++) {
            $result = $this->service()->redeem('CAP00003', $this->user("u{$i}@example.com"));
            if ($result->ok && ! $result->already) {
                $wins++;
            }
            // The invariant must hold after EVERY claim.
            $this->assertLessThanOrEqual($k, $code->fresh()->current_uses);
        }

        $this->assertSame($k, $wins, 'Exactly K distinct accounts win a seat');
        $code->refresh();
        $this->assertSame($k, $code->current_uses);
        $this->assertSame(InviteCode::STATE_EXHAUSTED, $code->state);
    }

    public function test_multi_use_stays_active_until_last_seat(): void
    {
        $code = $this->code('TWSEAT22', maxUses: 2);
        $this->service()->redeem('TWSEAT22', $this->user('m1@example.com'));

        $code->refresh();
        $this->assertSame(InviteCode::STATE_ACTIVE, $code->state, 'Still active with a seat to spare');
        $this->assertSame(1, $code->current_uses);
    }

    public function test_unknown_code_is_invalid(): void
    {
        $result = $this->service()->redeem('NOPE9999', $this->user('x@example.com'));

        $this->assertFalse($result->ok);
        $this->assertSame(RedemptionError::Invalid, $result->error);
    }

    public function test_expired_code_returns_expired(): void
    {
        $code = $this->code('EXP00001', maxUses: 1);
        $code->update(['expires_at' => now()->subDay()]);

        $result = $this->service()->redeem('EXP00001', $this->user('e@example.com'));

        $this->assertFalse($result->ok);
        $this->assertSame(RedemptionError::Expired, $result->error);
    }

    public function test_revoked_code_returns_revoked(): void
    {
        $code = $this->code('REV00001', maxUses: 1);
        $code->update(['state' => InviteCode::STATE_REVOKED]);

        $result = $this->service()->redeem('REV00001', $this->user('r@example.com'));

        $this->assertFalse($result->ok);
        $this->assertSame(RedemptionError::Revoked, $result->error);
    }

    public function test_exhausted_code_returns_exhausted(): void
    {
        $code = $this->code('FNE00001', maxUses: 1);
        $this->service()->redeem('FNE00001', $this->user('first@example.com'));

        $result = $this->service()->redeem('FNE00001', $this->user('second@example.com'));

        $this->assertFalse($result->ok);
        $this->assertSame(RedemptionError::Exhausted, $result->error);
    }

    public function test_paused_campaign_makes_code_ineligible(): void
    {
        $campaign = InviteCampaign::create([
            'key' => 'paused-camp',
            'name' => 'Paused',
            'type' => InviteCampaign::TYPE_MULTI_USE,
            'status' => InviteCampaign::STATUS_PAUSED,
            'created_by' => $this->user('admin@example.com')->id,
        ]);
        $code = $this->code('PAZED001', maxUses: 5);
        $code->update(['campaign_id' => $campaign->id]);

        $result = $this->service()->redeem('PAZED001', $this->user('g@example.com'));

        $this->assertFalse($result->ok);
        $this->assertSame(RedemptionError::Ineligible, $result->error);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
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
