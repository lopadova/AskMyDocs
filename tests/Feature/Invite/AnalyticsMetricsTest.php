<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Models\InviteAnalyticsEvent;
use App\Models\InviteCampaign;
use App\Models\InviteCode;
use App\Models\User;
use App\Services\Invite\MetricsService;
use App\Services\Invite\RedemptionService;
use App\Services\Invite\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 5 (analytics) DoD — funnel events captured at each transition,
 * idempotent ingestion, and metrics reconciled against canonical counts.
 */
final class AnalyticsMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_redeemed_event_recorded_once_and_idempotent(): void
    {
        $this->code('TRACK001', maxUses: 3);
        $user = $this->user('t@example.com');

        app(RedemptionService::class)->redeem('TRACK001', $user);
        app(RedemptionService::class)->redeem('TRACK001', $user); // idempotent replay

        $this->assertSame(1, InviteAnalyticsEvent::where('event_type', InviteAnalyticsEvent::TYPE_CODE_REDEEMED)->count());
        $this->assertNull(
            InviteAnalyticsEvent::first()->actor_hash === (string) $user->id ? 'raw' : null,
            'actor is hashed, not the raw id',
        );
    }

    public function test_qualification_emits_referral_and_reward_events(): void
    {
        $campaign = $this->referralCampaign();
        $issuer = $this->user('iss@example.com');
        $code = $this->code('TRACK002', maxUses: 5, issuerId: $issuer->id, campaignId: $campaign->id);
        $referee = $this->user('ref@example.com');

        $referral = app(RedemptionService::class)->redeem('TRACK002', $referee)->referral;
        app(ReferralService::class)->qualify($referral);

        foreach ([
            InviteAnalyticsEvent::TYPE_REFERRAL_QUALIFIED,
            InviteAnalyticsEvent::TYPE_ACCOUNT_ACTIVATED,
            InviteAnalyticsEvent::TYPE_REWARD_GRANTED,
        ] as $type) {
            $this->assertGreaterThanOrEqual(1, InviteAnalyticsEvent::where('event_type', $type)->count(), "missing {$type}");
        }
    }

    public function test_metrics_summary_reconciles_with_canonical_counts(): void
    {
        $campaign = $this->referralCampaign();
        $issuer = $this->user('owner2@example.com');

        // Two codes under the campaign; one is redeemed.
        $this->code('METRC001', maxUses: 5, issuerId: $issuer->id, campaignId: $campaign->id);
        $this->code('METRC002', maxUses: 5, issuerId: $issuer->id, campaignId: $campaign->id);
        app(RedemptionService::class)->redeem('METRC001', $this->user('m1@example.com'));

        $metrics = app(MetricsService::class)->summary($campaign->id);

        $this->assertSame(2, $metrics['codes_issued']);
        $this->assertSame(1, $metrics['redemptions']);
        $this->assertSame(0.5, $metrics['conversion_rate']);
        // One attributed referral (issuer→m1), one distinct referrer.
        $this->assertSame(1, $metrics['distinct_referrers']);
    }

    private function referralCampaign(): InviteCampaign
    {
        return InviteCampaign::create([
            'key' => 'analytics-ref', 'name' => 'Ref', 'type' => InviteCampaign::TYPE_REFERRAL,
            'status' => InviteCampaign::STATUS_ACTIVE, 'created_by' => $this->user('owner@example.com')->id,
            'reward_policy' => ['referrer' => ['type' => 'credit', 'amount' => 100, 'trigger' => 'on_activation']],
        ]);
    }

    private function user(string $email): User
    {
        return User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('secret123')]);
    }

    private function code(string $code, int $maxUses, ?int $issuerId = null, ?int $campaignId = null): InviteCode
    {
        return InviteCode::create([
            'code' => $code, 'code_kind' => InviteCode::KIND_RANDOM, 'state' => InviteCode::STATE_ACTIVE,
            'max_uses' => $maxUses, 'current_uses' => 0, 'issuer_id' => $issuerId, 'campaign_id' => $campaignId,
        ]);
    }
}
