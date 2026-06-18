<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\AbuseSignal;
use App\Models\Invitation;
use App\Models\Redemption;
use App\Services\Invite\Support\PiiHasher;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * GDPR retention + erasure (docs/15-security-privacy.md, Phase 6).
 *
 * The cardinal rule: anonymization NEVER deletes the rows the aggregates need.
 * Redemptions, referrals, rewards and analytics events stay; only their PII
 * COLUMNS are overwritten in place. So after a retention sweep or an erasure
 * request, `current_uses`, funnel counts, and K-factor are unchanged while the
 * subject's ip / fingerprint / recipient are gone.
 *
 * All operations are tenant-scoped (R30) and memory-safe (chunkById, R3).
 */
final class ErasureService
{
    private const ERASED = 'erased';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PiiHasher $hasher,
    ) {
    }

    /**
     * Anonymize PII older than the retention window, in place. Returns a count
     * summary. `$dryRun` reports what WOULD be anonymized without writing.
     *
     * @return array<string, int>
     */
    public function sweepRetention(int $days, bool $dryRun = false): array
    {
        $tenantId = $this->tenant->current();
        $cutoff = Carbon::now()->subDays($days);

        $redemptionQuery = Redemption::query()
            ->forTenant($tenantId)
            ->where('redeemed_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNotNull('ip')->orWhereNotNull('user_agent')->orWhereNotNull('fingerprint'));

        $signalQuery = AbuseSignal::query()
            ->forTenant($tenantId)
            ->where('created_at', '<', $cutoff)
            ->whereIn('subject_type', [AbuseSignal::SUBJECT_IP, AbuseSignal::SUBJECT_EMAIL, AbuseSignal::SUBJECT_FINGERPRINT])
            ->where('subject_value', '!=', self::ERASED);

        $invitationQuery = Invitation::query()
            ->forTenant($tenantId)
            ->whereIn('status', [Invitation::STATUS_ACCEPTED, Invitation::STATUS_EXPIRED, Invitation::STATUS_CANCELLED, Invitation::STATUS_BOUNCED])
            ->where('updated_at', '<', $cutoff)
            ->where('recipient', '!=', self::ERASED);

        if ($dryRun) {
            return [
                'redemptions' => $redemptionQuery->count(),
                'abuse_signals' => $signalQuery->count(),
                'invitations' => $invitationQuery->count(),
            ];
        }

        $summary = ['redemptions' => 0, 'abuse_signals' => 0, 'invitations' => 0];

        $redemptionQuery->chunkById(500, function ($rows) use (&$summary): void {
            foreach ($rows as $row) {
                $row->update(['ip' => null, 'user_agent' => null, 'fingerprint' => null]);
                $summary['redemptions']++;
            }
        });

        $signalQuery->chunkById(500, function ($rows) use (&$summary): void {
            foreach ($rows as $row) {
                $row->update(['subject_value' => self::ERASED]);
                $summary['abuse_signals']++;
            }
        });

        $invitationQuery->chunkById(500, function ($rows) use (&$summary): void {
            foreach ($rows as $row) {
                $row->update(['recipient' => self::ERASED, 'token' => null]);
                $summary['invitations']++;
            }
        });

        return $summary;
    }

    /**
     * Erase one subject's PII across every invite table — a GDPR right-to-be-
     * forgotten request — WITHOUT corrupting aggregates. The redemption rows,
     * current_uses, referrals, rewards and analytics counts are untouched;
     * only ip / fingerprint / recipient / matched subject_value are nulled.
     *
     * @return array<string, int>
     */
    public function eraseAccount(int $accountId, ?string $email = null): array
    {
        $tenantId = $this->tenant->current();
        $summary = ['redemptions' => 0, 'invitations' => 0, 'abuse_signals' => 0];

        $summary['redemptions'] = Redemption::query()
            ->forTenant($tenantId)
            ->where('redeemer_id', $accountId)
            ->update(['ip' => null, 'user_agent' => null, 'fingerprint' => null]);

        // Account-subject abuse signals.
        $summary['abuse_signals'] += AbuseSignal::query()
            ->forTenant($tenantId)
            ->where('subject_type', AbuseSignal::SUBJECT_ACCOUNT)
            ->where('subject_value', (string) $accountId)
            ->update(['subject_value' => self::ERASED]);

        if ($email !== null) {
            $normalized = strtolower(trim($email));

            $summary['invitations'] = Invitation::query()
                ->forTenant($tenantId)
                ->where('recipient', $normalized)
                ->update(['recipient' => self::ERASED, 'token' => null]);

            // Email/fingerprint abuse signals are stored as a salted hash of
            // the canonical email — erase the matching subject.
            $emailHash = $this->hasher->hash($this->hasher->canonicalizeEmail($normalized));
            $summary['abuse_signals'] += AbuseSignal::query()
                ->forTenant($tenantId)
                ->where('subject_type', AbuseSignal::SUBJECT_EMAIL)
                ->where('subject_value', $emailHash)
                ->update(['subject_value' => self::ERASED]);
        }

        return $summary;
    }
}
