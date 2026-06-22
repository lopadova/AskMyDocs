<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Models\AbuseSignal;
use App\Models\InviteCampaign;
use App\Models\InviteCode;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Invite\FraudDetector;
use App\Services\Invite\RedemptionService;
use App\Services\Invite\Support\AssessmentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 4 DoD — weighted fraud detector + advisory fail-open gate.
 */
final class FraudDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function detector(): FraudDetector
    {
        return app(FraudDetector::class);
    }

    public function test_clean_context_returns_none_and_persists_nothing(): void
    {
        $decision = $this->detector()->assess(new AssessmentContext(
            tenantId: 'default', action: 'redeem', accountId: 1, email: 'clean@example.com',
        ));

        $this->assertSame(AbuseSignal::ACTION_NONE, $decision->action);
        $this->assertFalse($decision->blocked());
        $this->assertSame(0, AbuseSignal::count());
    }

    public function test_honeypot_forces_block(): void
    {
        $decision = $this->detector()->assess(new AssessmentContext(
            tenantId: 'default', action: 'redeem', accountId: 1, ip: '203.0.113.5', honeypot: true,
        ));

        $this->assertSame(AbuseSignal::ACTION_BLOCK, $decision->action);
        $this->assertTrue($decision->blocked());
        $this->assertSame(1, AbuseSignal::where('signal_type', AbuseSignal::TYPE_HONEYPOT)->count());
    }

    public function test_disposable_email_blocks_on_reward_campaign_but_flags_otherwise(): void
    {
        $campaign = InviteCampaign::create([
            'key' => 'ref-camp', 'name' => 'Ref', 'type' => InviteCampaign::TYPE_REFERRAL,
            'status' => InviteCampaign::STATUS_ACTIVE, 'created_by' => $this->user('o@example.com')->id,
            'reward_policy' => ['referrer' => ['type' => 'credit', 'amount' => 100]],
        ]);

        $reward = $this->detector()->assess(new AssessmentContext(
            tenantId: 'default', action: 'redeem', accountId: 2, email: 'bot@mailinator.com', campaign: $campaign,
        ));
        $this->assertSame(AbuseSignal::ACTION_BLOCK, $reward->action);

        $soft = $this->detector()->assess(new AssessmentContext(
            tenantId: 'default', action: 'redeem', accountId: 3, email: 'bot2@mailinator.com',
        ));
        $this->assertSame(AbuseSignal::ACTION_FLAG, $soft->action, 'Soft campaign only flags');
        $this->assertFalse($soft->blocked(), 'flag proceeds');
    }

    public function test_blacklisted_ip_blocks_and_persists_hashed_subject(): void
    {
        $hasher = app(\App\Services\Invite\Support\PiiHasher::class);
        config(['invite.anti_abuse.blocklist.ip_hashes' => [$hasher->hash('198.51.100.9')]]);

        $decision = $this->detector()->assess(new AssessmentContext(
            tenantId: 'default', action: 'redeem', accountId: 4, ip: '198.51.100.9',
        ));

        $this->assertSame(AbuseSignal::ACTION_BLOCK, $decision->action);
        $signal = AbuseSignal::where('signal_type', AbuseSignal::TYPE_BLACKLIST)->firstOrFail();
        $this->assertNotSame('198.51.100.9', $signal->subject_value, 'IP stored hashed, never raw');
    }

    public function test_allowlisted_account_skips_scoring(): void
    {
        config(['invite.anti_abuse.allowlist.accounts' => [42]]);

        $decision = $this->detector()->assess(new AssessmentContext(
            tenantId: 'default', action: 'redeem', accountId: 42, ip: '203.0.113.5', honeypot: true,
        ));

        $this->assertSame(AbuseSignal::ACTION_NONE, $decision->action, 'Allowlist skips even a honeypot trip');
        $this->assertSame(0, AbuseSignal::count());
    }

    public function test_disabled_detector_is_a_noop(): void
    {
        config(['invite.anti_abuse.enabled' => false]);

        $decision = $this->detector()->assess(new AssessmentContext(
            tenantId: 'default', action: 'redeem', accountId: 5, honeypot: true,
        ));

        $this->assertSame(AbuseSignal::ACTION_NONE, $decision->action);
        $this->assertSame(0, AbuseSignal::count());
    }

    public function test_account_velocity_flags_after_threshold(): void
    {
        config(['invite.anti_abuse.velocity.account' => ['max' => 3, 'window' => 86400, 'score' => 30]]);
        $user = $this->user('fast@example.com');

        // 3 prior redemptions for the account (distinct codes).
        foreach (range(1, 3) as $i) {
            Redemption::create([
                'tenant_id' => 'default', 'code_id' => $this->code("VCT0000{$i}")->id,
                'redeemer_id' => $user->id, 'redeemed_at' => now()->subMinutes(5),
            ]);
        }

        $decision = $this->detector()->assess(new AssessmentContext(
            tenantId: 'default', action: 'redeem', accountId: $user->id,
        ));

        $this->assertSame(AbuseSignal::ACTION_FLAG, $decision->action, 'velocity score 30 → flag');
        $this->assertSame(1, AbuseSignal::where('signal_type', AbuseSignal::TYPE_VELOCITY)->count());
    }

    public function test_throttle_returns_generic_rate_limited_through_redemption_gate(): void
    {
        // Raise disposable weight into the throttle band on a soft campaign.
        config(['invite.anti_abuse.disposable_score' => 50]);
        $code = $this->code('THRT0001');
        $user = $this->user('bot@guerrillamail.com');

        $result = app(RedemptionService::class)->redeem('THRT0001', $user, ['ip' => '203.0.113.7']);

        $this->assertFalse($result->ok);
        $this->assertSame(\App\Services\Invite\Support\RedemptionError::RateLimited, $result->error);
        $this->assertSame(0, Redemption::where('code_id', $code->id)->count(), 'No claim on a throttled request');
    }

    public function test_honeypot_blocks_redemption_with_generic_error(): void
    {
        $code = $this->code('HNY00001');
        $user = $this->user('legit@example.com');

        $result = app(RedemptionService::class)->redeem('HNY00001', $user, ['honeypot' => true]);

        $this->assertFalse($result->ok);
        $this->assertSame(\App\Services\Invite\Support\RedemptionError::RateLimited, $result->error);
        $this->assertSame(0, Redemption::count());
    }

    private function user(string $email): User
    {
        return User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('secret123')]);
    }

    private function code(string $code): InviteCode
    {
        return InviteCode::create([
            'code' => $code, 'code_kind' => InviteCode::KIND_RANDOM, 'state' => InviteCode::STATE_ACTIVE,
            'max_uses' => 1, 'current_uses' => 0,
        ]);
    }
}
