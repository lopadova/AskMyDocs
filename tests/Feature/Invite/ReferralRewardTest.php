<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Models\AbuseSignal;
use App\Models\InviteCampaign;
use App\Models\InviteCode;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\User;
use App\Services\Invite\RedemptionService;
use App\Services\Invite\ReferralService;
use App\Services\Invite\RewardEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 3 DoD — referral attribution + double-sided idempotent rewards.
 */
final class ReferralRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_redemption_attributes_a_referral_to_the_issuer(): void
    {
        $issuer = $this->user('issuer@example.com');
        $code = $this->code('ATTRB001', $issuer->id);
        $redeemer = $this->user('newbie@example.com');

        $result = app(RedemptionService::class)->redeem('ATTRB001', $redeemer);

        $this->assertTrue($result->ok);
        $this->assertNotNull($result->referral);
        $this->assertSame($issuer->id, $result->referral->referrer_id);
        $this->assertSame($redeemer->id, $result->referral->referee_id);
        $this->assertSame(Referral::STATUS_PENDING, $result->referral->status);
    }

    public function test_self_referral_is_rejected_and_flagged(): void
    {
        $issuer = $this->user('self@example.com');
        $code = $this->code('SEFREF01', $issuer->id);

        $result = app(RedemptionService::class)->redeem('SEFREF01', $issuer);

        $this->assertTrue($result->ok, 'Redemption still succeeds');
        $this->assertNull($result->referral, 'No self-referral edge');
        $this->assertSame(1, AbuseSignal::where('signal_type', AbuseSignal::TYPE_SELF_REFERRAL)->count());
    }

    public function test_one_referrer_per_referee_first_wins(): void
    {
        $issuerA = $this->user('a@example.com');
        $issuerB = $this->user('b@example.com');
        $referee = $this->user('shared@example.com');

        // Same referee redeems two codes from two issuers; the multi-use code
        // allows a second claim by a different account but only the FIRST
        // attribution sticks (UNIQUE(tenant_id, referee_id)).
        $codeA = $this->code('FRMAAA01', $issuerA->id, maxUses: 5);
        $codeB = $this->code('FRMBBB01', $issuerB->id, maxUses: 5);

        $first = app(RedemptionService::class)->redeem('FRMAAA01', $referee);
        $second = app(RedemptionService::class)->redeem('FRMBBB01', $referee);

        $this->assertNotNull($first->referral);
        $this->assertNull($second->referral, 'Second attribution dropped (first-wins)');
        $this->assertSame(1, Referral::where('referee_id', $referee->id)->count());
        $this->assertSame($issuerA->id, Referral::where('referee_id', $referee->id)->first()->referrer_id);
    }

    public function test_qualification_grants_double_sided_reward_once_per_key(): void
    {
        $campaign = $this->referralCampaign();
        $issuer = $this->user('ref@example.com');
        $code = $this->code('REWARD01', $issuer->id, maxUses: 5, campaignId: $campaign->id);
        $referee = $this->user('ref2@example.com');

        $referral = app(RedemptionService::class)->redeem('REWARD01', $referee)->referral;
        $this->assertNotNull($referral);

        $referrals = app(ReferralService::class);
        $referrals->qualify($referral);
        $referrals->qualify($referral->refresh()); // replay — must not double-grant

        $this->assertSame(2, Reward::count(), 'Exactly one referrer + one referee reward');
        $this->assertSame(1, Reward::where('party', Reward::PARTY_REFERRER)->count());
        $this->assertSame(1, Reward::where('party', Reward::PARTY_REFEREE)->count());
        $this->assertSame(Referral::STATUS_REWARDED, $referral->refresh()->status);
    }

    public function test_reversal_flips_reward_and_referral_and_is_idempotent(): void
    {
        $campaign = $this->referralCampaign();
        $issuer = $this->user('rv@example.com');
        $code = $this->code('REVERSE1', $issuer->id, maxUses: 5, campaignId: $campaign->id);
        $referee = $this->user('rv2@example.com');

        $referral = app(RedemptionService::class)->redeem('REVERSE1', $referee)->referral;
        app(ReferralService::class)->qualify($referral);

        $reward = Reward::where('party', Reward::PARTY_REFERRER)->firstOrFail();
        $engine = app(RewardEngine::class);
        $engine->reverse($reward);
        $engine->reverse($reward->refresh()); // idempotent

        $this->assertSame(Reward::STATE_REVERSED, $reward->refresh()->state);
        $this->assertSame(Referral::STATUS_REVERSED, $referral->refresh()->status);
    }

    private function referralCampaign(): InviteCampaign
    {
        return InviteCampaign::create([
            'key' => 'refer-a-friend',
            'name' => 'Refer a Friend',
            'type' => InviteCampaign::TYPE_REFERRAL,
            'status' => InviteCampaign::STATUS_ACTIVE,
            'created_by' => $this->user('owner@example.com')->id,
            'reward_policy' => [
                'referrer' => ['type' => 'credit', 'amount' => 500, 'unit' => 'cents', 'trigger' => 'on_activation'],
                'referee' => ['type' => 'credit', 'amount' => 200, 'unit' => 'cents', 'trigger' => 'on_activation'],
                'per_referrer_total' => 50,
            ],
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

    private function code(string $code, int $issuerId, int $maxUses = 1, ?int $campaignId = null): InviteCode
    {
        return InviteCode::create([
            'code' => $code,
            'code_kind' => InviteCode::KIND_RANDOM,
            'state' => InviteCode::STATE_ACTIVE,
            'max_uses' => $maxUses,
            'current_uses' => 0,
            'issuer_id' => $issuerId,
            'campaign_id' => $campaignId,
        ]);
    }
}
