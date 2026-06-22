<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\InviteCode;
use App\Models\Invitation;
use App\Models\Redemption;
use App\Models\Referral;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Funnel + virality metrics (docs/11-analytics.md), computed from the CANONICAL
 * tables (Redemption / Referral / Invitation / InviteCode) — the source of
 * truth — so the numbers reconcile with the raw rows rather than drifting from
 * a separate rollup. The analytics event log is the audit trail; this service
 * is the read model.
 *
 * Definitions:
 *   - k_factor       = qualified referrals / distinct referrers (viral coeff.)
 *   - acceptance     = accepted invitations / sent invitations
 *   - conversion     = redemptions / codes issued
 *   - ttr_p50/p90    = time from code creation to redemption (seconds)
 */
final class MetricsService
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    /**
     * @return array<string, int|float|null>
     */
    public function summary(?int $campaignId = null, ?int $sinceDays = null): array
    {
        $tenantId = $this->tenant->current();
        $since = $sinceDays !== null ? Carbon::now()->subDays($sinceDays) : null;

        $codesIssued = InviteCode::query()
            ->forTenant($tenantId)
            ->when($campaignId !== null, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->count();

        $redemptions = Redemption::query()
            ->forTenant($tenantId)
            ->when($campaignId !== null, fn ($q) => $q->whereIn('code_id', $this->campaignCodeIds($tenantId, $campaignId)))
            ->when($since !== null, fn ($q) => $q->where('redeemed_at', '>=', $since))
            ->count();

        $invitesSent = Invitation::query()
            ->forTenant($tenantId)
            ->whereNotNull('sent_at')
            ->when($since !== null, fn ($q) => $q->where('sent_at', '>=', $since))
            ->count();

        $invitesAccepted = Invitation::query()
            ->forTenant($tenantId)
            ->where('status', Invitation::STATUS_ACCEPTED)
            ->when($since !== null, fn ($q) => $q->where('accepted_at', '>=', $since))
            ->count();

        $qualified = Referral::query()
            ->forTenant($tenantId)
            ->whereIn('status', [Referral::STATUS_QUALIFIED, Referral::STATUS_REWARDED])
            ->when($campaignId !== null, fn ($q) => $q->where('campaign_id', $campaignId))
            ->count();

        $distinctReferrers = Referral::query()
            ->forTenant($tenantId)
            ->when($campaignId !== null, fn ($q) => $q->where('campaign_id', $campaignId))
            ->distinct()
            ->count('referrer_id');

        return [
            'codes_issued' => $codesIssued,
            'redemptions' => $redemptions,
            'invites_sent' => $invitesSent,
            'invites_accepted' => $invitesAccepted,
            'referrals_qualified' => $qualified,
            'distinct_referrers' => $distinctReferrers,
            'k_factor' => $distinctReferrers > 0 ? round($qualified / $distinctReferrers, 4) : 0.0,
            'acceptance_rate' => $invitesSent > 0 ? round($invitesAccepted / $invitesSent, 4) : 0.0,
            'conversion_rate' => $codesIssued > 0 ? round($redemptions / $codesIssued, 4) : 0.0,
            'ttr_p50_seconds' => $this->ttrPercentile($tenantId, $campaignId, 0.50),
            'ttr_p90_seconds' => $this->ttrPercentile($tenantId, $campaignId, 0.90),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function campaignCodeIds(string $tenantId, int $campaignId): array
    {
        return InviteCode::query()
            ->forTenant($tenantId)
            ->where('campaign_id', $campaignId)
            ->pluck('id')
            ->all();
    }

    private function ttrPercentile(string $tenantId, ?int $campaignId, float $percentile): ?int
    {
        $rows = Redemption::query()
            ->forTenant($tenantId)
            ->join('invite_codes', 'invite_codes.id', '=', 'invite_redemptions.code_id')
            ->when($campaignId !== null, fn ($q) => $q->where('invite_codes.campaign_id', $campaignId))
            ->whereNotNull('invite_codes.created_at')
            ->selectRaw('invite_redemptions.redeemed_at as r, invite_codes.created_at as c')
            ->get();

        $deltas = $rows
            ->map(fn ($row): int => max(0, Carbon::parse($row->r)->getTimestamp() - Carbon::parse($row->c)->getTimestamp()))
            ->sort()
            ->values();

        if ($deltas->isEmpty()) {
            return null;
        }

        $index = (int) floor($percentile * ($deltas->count() - 1));

        return (int) $deltas[$index];
    }
}
