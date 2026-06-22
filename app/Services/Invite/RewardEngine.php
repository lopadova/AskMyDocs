<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\AbuseSignal;
use App\Models\InviteCampaign;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Invite\Support\AssessmentContext;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;

/**
 * The double-sided reward engine (docs/09-rewards-engine.md). GREENFIELD.
 *
 * The double-grant guard is the database: every Reward carries a deterministic
 * `idempotency_key`, and UNIQUE(idempotency_key) — not application bookkeeping —
 * makes a replayed grant a no-op. A per-referrer cap (campaign
 * `reward_policy.per_referrer_total`) skips further grants and emits an
 * AbuseSignal once a referrer saturates it.
 */
final class RewardEngine
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FraudDetector $fraud,
        private readonly AnalyticsTracker $analytics,
    ) {
    }

    /**
     * Grant the policy-defined reward for one party of a referral, if any.
     * Idempotent on (referral, party): re-calling never double-grants.
     * Returns the Reward (existing or new), or null when the policy defines no
     * reward for this party or the per-referrer cap is hit.
     */
    public function grantForReferral(Referral $referral, string $party): ?Reward
    {
        $policy = $this->partyPolicy($referral->campaign, $party);
        if ($policy === null) {
            return null;
        }

        $beneficiaryId = $party === Reward::PARTY_REFERRER ? $referral->referrer_id : $referral->referee_id;
        $key = $this->idempotencyKey($referral, $party);

        $existing = Reward::query()
            ->forTenant($this->tenant->current())
            ->where('idempotency_key', $key)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        // Per-referrer cap — count granted referrer-side rewards for this account.
        if ($party === Reward::PARTY_REFERRER && $this->capReached($referral)) {
            $this->emitCapSignal($referral);

            return null;
        }

        // Advisory anti-abuse gate on the grant (Phase 4 DoD — reward grant is
        // gated on assess too). Fail-open: a blocked beneficiary just doesn't
        // get the grant; the redemption itself already committed.
        $decision = $this->fraud->assess(new AssessmentContext(
            tenantId: $this->tenant->current(),
            action: 'reward_grant',
            accountId: $beneficiaryId,
            campaign: $referral->campaign,
        ));
        if ($decision->blocked()) {
            return null;
        }

        try {
            $reward = Reward::create([
                'tenant_id' => $this->tenant->current(),
                'referral_id' => $referral->id,
                'redemption_id' => $referral->redemption_id,
                'beneficiary_id' => $beneficiaryId,
                'party' => $party,
                'type' => $policy['type'] ?? Reward::TYPE_CREDIT,
                'amount' => $policy['amount'] ?? null,
                'unit' => $policy['unit'] ?? null,
                'trigger' => $policy['trigger'] ?? Reward::TRIGGER_ON_ACTIVATION,
                'state' => Reward::STATE_GRANTED,
                'idempotency_key' => $key,
                'granted_at' => now(),
            ]);

            // Funnel event (Phase 5) — idempotent on the reward id.
            $this->analytics->record(
                \App\Models\InviteAnalyticsEvent::TYPE_REWARD_GRANTED,
                "reward:{$reward->id}",
                ['account_id' => $beneficiaryId, 'referral_id' => $referral->id, 'campaign_id' => $referral->campaign_id],
            );

            return $reward;
        } catch (QueryException $e) {
            // A concurrent grant beat us on the UNIQUE(idempotency_key) — load
            // and return it as the idempotent result.
            if ($this->isUniqueViolation($e)) {
                return Reward::query()
                    ->forTenant($this->tenant->current())
                    ->where('idempotency_key', $key)
                    ->first();
            }
            throw $e;
        }
    }

    /**
     * Reverse a granted reward (fraud / chargeback). Flips granted→reversed,
     * moves the source referral→reversed, and is a no-op on a second call.
     */
    public function reverse(Reward $reward): Reward
    {
        if ($reward->state !== Reward::STATE_GRANTED) {
            return $reward;
        }

        $reward->update([
            'state' => Reward::STATE_REVERSED,
            'reversed_at' => now(),
        ]);

        if ($reward->referral_id !== null) {
            Referral::query()
                ->forTenant($this->tenant->current())
                ->where('id', $reward->referral_id)
                ->update(['status' => Referral::STATUS_REVERSED]);
        }

        return $reward->refresh();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function partyPolicy(?InviteCampaign $campaign, string $party): ?array
    {
        $policy = $campaign?->reward_policy;
        if (! is_array($policy) || ! isset($policy[$party]) || ! is_array($policy[$party])) {
            return null;
        }

        return $policy[$party];
    }

    private function capReached(Referral $referral): bool
    {
        $cap = $referral->campaign?->reward_policy['per_referrer_total'] ?? null;
        if (! is_int($cap) && ! (is_string($cap) && ctype_digit($cap))) {
            return false;
        }

        $granted = Reward::query()
            ->forTenant($this->tenant->current())
            ->where('beneficiary_id', $referral->referrer_id)
            ->where('party', Reward::PARTY_REFERRER)
            ->where('state', Reward::STATE_GRANTED)
            ->count();

        return $granted >= (int) $cap;
    }

    private function emitCapSignal(Referral $referral): void
    {
        AbuseSignal::create([
            'tenant_id' => $this->tenant->current(),
            'subject_type' => AbuseSignal::SUBJECT_ACCOUNT,
            'subject_value' => (string) $referral->referrer_id,
            'signal_type' => AbuseSignal::TYPE_RATE_LIMIT,
            'severity' => AbuseSignal::SEVERITY_WARN,
            'action_taken' => AbuseSignal::ACTION_THROTTLE,
            'context' => ['reason' => 'per_referrer_total reached', 'campaign_id' => $referral->campaign_id],
            'created_at' => now(),
        ]);
    }

    private function idempotencyKey(Referral $referral, string $party): string
    {
        return "reward:{$referral->tenant_id}:{$referral->id}:{$party}";
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
