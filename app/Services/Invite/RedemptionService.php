<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\InviteCode;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Invite\Support\AssessmentContext;
use App\Services\Invite\Support\InviteGrant;
use App\Services\Invite\Support\RedemptionError;
use App\Services\Invite\Support\RedemptionResult;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The atomic, idempotent, concurrency-safe redemption service
 * (docs/07-redemption-flow.md). This is the ONLY sanctioned path to bump
 * `invite_codes.current_uses`.
 *
 * Algorithm (conditional-UPDATE strategy — lock-free):
 *   1. Validate (advisory: unknown / expired / revoked / campaign-closed).
 *   2. Idempotency pre-check: an existing Redemption for (code, redeemer)
 *      returns idempotent success — no second increment.
 *   3. Atomic claim: a single conditional UPDATE that increments
 *      `current_uses` AND flips `state` in the same statement, gated on
 *      `state='active' AND current_uses < max_uses`. affected-rows = 0 means
 *      "lost the race / exhausted" — unless the redeemer already has a row
 *      (a concurrent same-account claim committed first → idempotent).
 *   4. INSERT the Redemption. A UNIQUE(code_id, redeemer_id) violation means
 *      a concurrent same-account claim beat us between step 2 and step 4:
 *      hand the over-counted seat back and return idempotent success — never
 *      an error.
 *
 * `current_uses` can NEVER exceed `max_uses`: the WHERE clause is the gate, so
 * at most `max_uses` callers ever see affected-rows = 1.
 */
final class RedemptionService
{
    public function __construct(
        private readonly CodeValidator $validator,
        private readonly TenantContext $tenant,
        private readonly ReferralService $referrals,
        private readonly FraudDetector $fraud,
        private readonly AnalyticsTracker $analytics,
        private readonly AccountProvisioningService $provisioning,
    ) {
    }

    /**
     * @param  array{ip?: string|null, user_agent?: string|null, fingerprint?: string|null, invitation_id?: int|null}  $context
     */
    public function redeem(string $rawCode, User $redeemer, array $context = []): RedemptionResult
    {
        $tenantId = $this->tenant->current();

        $validation = $this->validator->validate($rawCode, $tenantId);
        if (! $validation->ok) {
            return RedemptionResult::failure($validation->error);
        }

        $code = $validation->code;

        // (2) Idempotency pre-check — same account, same code → replay. Run
        // BEFORE the abuse gate so a legitimate replay is never rate-limited.
        $existing = $this->findRedemption($tenantId, $code->id, $redeemer->id);
        if ($existing !== null) {
            return RedemptionResult::success($existing, already: true);
        }

        // (2b) Advisory anti-abuse gate (Phase 4). Fail-open: a detector fault
        // returns `none` and never blocks. A throttle/block surfaces a GENERIC
        // rate_limited — the tripped signal_type is never echoed.
        $decision = $this->fraud->assess(new AssessmentContext(
            tenantId: $tenantId,
            action: 'redeem',
            accountId: $redeemer->id,
            ip: $context['ip'] ?? null,
            fingerprint: $context['fingerprint'] ?? null,
            email: $redeemer->email,
            campaign: $code->campaign,
            honeypot: (bool) ($context['honeypot'] ?? false),
            codeId: $code->id,
        ));
        if ($decision->blocked()) {
            return RedemptionResult::failure(RedemptionError::RateLimited);
        }

        // (3) Atomic claim: increment + state transition in one statement.
        $affected = $this->claimSeat($tenantId, $code);

        if ($affected === 0) {
            // Either genuinely full / not active, OR a concurrent same-account
            // claim committed between the pre-check and here.
            $raced = $this->findRedemption($tenantId, $code->id, $redeemer->id);
            if ($raced !== null) {
                return RedemptionResult::success($raced, already: true);
            }

            return RedemptionResult::failure(RedemptionError::Exhausted);
        }

        // (4) Record the immutable claim.
        try {
            $redemption = Redemption::create([
                'tenant_id' => $tenantId,
                'code_id' => $code->id,
                'redeemer_id' => $redeemer->id,
                'invitation_id' => $context['invitation_id'] ?? null,
                'redeemed_at' => now(),
                'ip' => $this->hashPii($context['ip'] ?? null),
                'user_agent' => $this->networkField($context['user_agent'] ?? null),
                'fingerprint' => $this->hashPii($context['fingerprint'] ?? null),
                'context' => [],
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Concurrent same-account claim beat us — give the seat back and
            // return the winner's Redemption as idempotent success.
            $this->releaseSeat($tenantId, $code->id);
            $winner = $this->findRedemption($tenantId, $code->id, $redeemer->id);

            // The winner is guaranteed to exist (the unique violation proves
            // a row is there), but guard defensively.
            return $winner !== null
                ? RedemptionResult::success($winner, already: true)
                : RedemptionResult::failure(RedemptionError::Exhausted);
        }

        // Funnel event (Phase 5) — idempotent on the redemption id, best-effort.
        $this->analytics->record(
            \App\Models\InviteAnalyticsEvent::TYPE_CODE_REDEEMED,
            "redeemed:{$redemption->id}",
            ['account_id' => $redeemer->id, 'code_id' => $code->id, 'campaign_id' => $code->campaign_id],
        );

        // Provision the redeemer's account from the invite key's grant (role +
        // tenant projects). The code's grant overrides its campaign default;
        // both may be absent (account-creation-only codes). Best-effort: the
        // service swallows + logs its own faults so this never fails the claim.
        $this->provisioning->provision(
            $redeemer,
            InviteGrant::resolve($code->grant, $code->campaign?->grant),
            $tenantId,
        );

        // Attribute the referral edge for this fresh claim (Phase 3). The
        // attributor returns null for un-attributable / first-wins / self
        // cases; attribution failure must never fail the redemption itself.
        $referral = $this->referrals->attribute($redemption, $code);

        return RedemptionResult::success($redemption, already: false, referral: $referral);
    }

    private function findRedemption(string $tenantId, int $codeId, int $redeemerId): ?Redemption
    {
        return Redemption::query()
            ->forTenant($tenantId)
            ->where('code_id', $codeId)
            ->where('redeemer_id', $redeemerId)
            ->first();
    }

    /**
     * The single conditional UPDATE. Returns affected-rows (0 or 1). The
     * CASE flips state in the SAME statement so there is no window where a
     * fully-consumed code is still `active`.
     */
    private function claimSeat(string $tenantId, InviteCode $code): int
    {
        return DB::table('invite_codes')
            ->where('id', $code->id)
            ->where('tenant_id', $tenantId)
            ->where('state', InviteCode::STATE_ACTIVE)
            ->whereColumn('current_uses', '<', 'max_uses')
            ->update([
                'current_uses' => DB::raw('current_uses + 1'),
                'state' => DB::raw(
                    "CASE "
                    . "WHEN current_uses + 1 >= max_uses AND max_uses = 1 THEN 'redeemed' "
                    . "WHEN current_uses + 1 >= max_uses THEN 'exhausted' "
                    . "ELSE state END"
                ),
                'updated_at' => now(),
            ]);
    }

    /**
     * Compensate an over-counted seat after a UNIQUE(code_id, redeemer_id)
     * violation: decrement and recompute the state. A code that was flipped to
     * redeemed/exhausted by our own increment returns to its correct state.
     */
    private function releaseSeat(string $tenantId, int $codeId): void
    {
        DB::table('invite_codes')
            ->where('id', $codeId)
            ->where('tenant_id', $tenantId)
            ->where('current_uses', '>', 0)
            ->update([
                'current_uses' => DB::raw('current_uses - 1'),
                'state' => DB::raw(
                    "CASE "
                    . "WHEN current_uses - 1 >= max_uses AND max_uses = 1 THEN 'redeemed' "
                    . "WHEN current_uses - 1 >= max_uses THEN 'exhausted' "
                    . "ELSE 'active' END"
                ),
                'updated_at' => now(),
            ]);
    }

    /**
     * Salted HMAC of a PII correlation key (ip / fingerprint). Plaintext is
     * never stored (docs/15-security-privacy.md). Null in → null out.
     */
    private function hashPii(?string $value): ?string
    {
        if ($value === null || $value === '' || ! config('invite.pii.store_network_fields', false)) {
            return null;
        }

        $salt = (string) (config('invite.pii.hash_salt') ?? config('app.key'));

        return hash_hmac('sha256', $value, $salt);
    }

    private function networkField(?string $value): ?string
    {
        if ($value === null || ! config('invite.pii.store_network_fields', false)) {
            return null;
        }

        // user_agent is PII-adjacent — truncate, keep only for fraud signal.
        return mb_substr($value, 0, 255);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 = pgsql unique_violation; SQLite surfaces SQLSTATE 23000 with
        // a "UNIQUE constraint failed" message.
        $sqlState = $e->getCode();

        if ($sqlState === '23505' || $sqlState === '23000') {
            return true;
        }

        return str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Unique violation');
    }
}
