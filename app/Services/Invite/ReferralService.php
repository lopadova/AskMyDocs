<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\AbuseSignal;
use App\Models\InviteCode;
use App\Models\Redemption;
use App\Models\Referral;
use App\Models\Reward;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;

/**
 * Referral attribution + qualification (docs/08-referral-graph.md). GREENFIELD.
 *
 * A redemption attributable to an inviter (the code's issuer) creates one
 * Referral. Two invariants are enforced both in code and in the schema:
 *   - no self-referral (CHECK + guard here; emits AbuseSignal(self_referral))
 *   - one referrer per referee (UNIQUE(tenant_id, referee_id), first-wins —
 *     a later attribution loses the race and is dropped with NO error)
 *
 * Qualification (pending→qualified) is idempotent and triggers the
 * double-sided reward grant via RewardEngine.
 */
final class ReferralService
{
    public function __construct(
        private readonly RewardEngine $rewards,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Attribute a referral for a fresh redemption. Returns the Referral, or
     * null when the redemption is not attributable (no issuer) or the referee
     * already has a referrer (first-wins). Self-referral is rejected and
     * flagged.
     */
    public function attribute(Redemption $redemption, InviteCode $code): ?Referral
    {
        $referrerId = $code->issuer_id;
        if ($referrerId === null) {
            return null; // standalone / un-attributable code
        }

        $refereeId = $redemption->redeemer_id;

        if ($referrerId === $refereeId) {
            $this->flagSelfReferral($refereeId);

            return null;
        }

        try {
            return Referral::create([
                'tenant_id' => $this->tenant->current(),
                'referrer_id' => $referrerId,
                'referee_id' => $refereeId,
                'code_id' => $code->id,
                'redemption_id' => $redemption->id,
                'campaign_id' => $code->campaign_id,
                'status' => Referral::STATUS_PENDING,
                'depth' => 1,
                'attributed_at' => now(),
            ]);
        } catch (QueryException $e) {
            // UNIQUE(tenant_id, referee_id) — this referee already has a
            // referrer. First-wins: drop silently, surface nothing to caller.
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Qualify a referral (referee met the activation bar). Idempotent:
     * pending→qualified once; re-firing does not re-grant. Grants the
     * double-sided rewards on qualification.
     *
     * @return array{referral: Referral, rewards: array<int, Reward>}
     */
    public function qualify(Referral $referral): array
    {
        if ($referral->status === Referral::STATUS_PENDING) {
            $referral->update([
                'status' => Referral::STATUS_QUALIFIED,
                'qualified_at' => now(),
            ]);
            $referral->refresh();
        }

        $rewards = [];
        foreach ([Reward::PARTY_REFERRER, Reward::PARTY_REFEREE] as $party) {
            $reward = $this->rewards->grantForReferral($referral, $party);
            if ($reward !== null) {
                $rewards[] = $reward;
            }
        }

        // Mark rewarded once at least one grant landed (idempotent transition).
        if ($rewards !== [] && $referral->status === Referral::STATUS_QUALIFIED) {
            $referral->update(['status' => Referral::STATUS_REWARDED]);
            $referral->refresh();
        }

        return ['referral' => $referral, 'rewards' => $rewards];
    }

    private function flagSelfReferral(int $accountId): void
    {
        AbuseSignal::create([
            'tenant_id' => $this->tenant->current(),
            'subject_type' => AbuseSignal::SUBJECT_ACCOUNT,
            'subject_value' => (string) $accountId,
            'signal_type' => AbuseSignal::TYPE_SELF_REFERRAL,
            'severity' => AbuseSignal::SEVERITY_WARN,
            'action_taken' => AbuseSignal::ACTION_FLAG,
            'context' => ['reason' => 'referrer == referee'],
            'created_at' => now(),
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->getCode();
        if ($sqlState === '23505' || $sqlState === '23000') {
            return true;
        }

        return str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Unique violation');
    }
}
