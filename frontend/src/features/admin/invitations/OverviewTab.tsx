import { useState } from 'react';
import { KpiCard, type KpiState } from '../dashboard/KpiCard';
import { useCampaigns, useInviteMetrics } from './use-invitations';
import type { InviteMetrics } from './invitations.api';
import { SelectFilter } from './SelectFilter';
import { filterRowStyle } from './tab-styles';
import { formatCompact, formatDuration, formatFactor, formatNumber, formatPercent } from './format';
import type { IconName } from '../../../components/Icons';

/*
 * Overview — the native invite-funnel dashboard. Replaces the old 3-KPI host
 * landing with the FULL metrics payload MetricsService::summary returns (11
 * fields) plus a proportional acquisition funnel. All values are R14-guarded
 * (non-finite → "—"); the funnel divides by a guarded max so an all-zero
 * tenant renders empty bars, never NaN width.
 */

const SINCE_OPTIONS = [
    { value: '7', label: 'Last 7 days' },
    { value: '30', label: 'Last 30 days' },
    { value: '90', label: 'Last 90 days' },
];

interface KpiSpec {
    slug: string;
    icon: IconName;
    label: string;
    value: (m: InviteMetrics | undefined) => string;
    hint?: string;
}

const KPIS: KpiSpec[] = [
    { slug: 'codes-issued', icon: 'Tag', label: 'Codes issued', value: (m) => formatNumber(m?.codes_issued) },
    { slug: 'redemptions', icon: 'Check', label: 'Redemptions', value: (m) => formatNumber(m?.redemptions) },
    { slug: 'invites-sent', icon: 'Send', label: 'Invites sent', value: (m) => formatNumber(m?.invites_sent) },
    { slug: 'invites-accepted', icon: 'ThumbsUp', label: 'Invites accepted', value: (m) => formatNumber(m?.invites_accepted) },
    { slug: 'referrals-qualified', icon: 'Share', label: 'Referrals qualified', value: (m) => formatNumber(m?.referrals_qualified) },
    { slug: 'distinct-referrers', icon: 'Users', label: 'Distinct referrers', value: (m) => formatNumber(m?.distinct_referrers) },
    { slug: 'k-factor', icon: 'Zap', label: 'K-factor', value: (m) => formatFactor(m?.k_factor), hint: 'Viral coefficient' },
    { slug: 'acceptance-rate', icon: 'Activity', label: 'Acceptance rate', value: (m) => formatPercent(m?.acceptance_rate) },
    { slug: 'conversion-rate', icon: 'Activity', label: 'Conversion rate', value: (m) => formatPercent(m?.conversion_rate) },
    { slug: 'ttr-p50', icon: 'Clock', label: 'Time-to-redeem p50', value: (m) => formatDuration(m?.ttr_p50_seconds) },
    { slug: 'ttr-p90', icon: 'Clock', label: 'Time-to-redeem p90', value: (m) => formatDuration(m?.ttr_p90_seconds) },
];

export function OverviewTab() {
    const [campaignId, setCampaignId] = useState('');
    const [since, setSince] = useState('');

    const campaignsQuery = useCampaigns();
    const query = useInviteMetrics({
        campaign_id: campaignId ? Number(campaignId) : null,
        since_days: since ? Number(since) : null,
    });

    // Metrics is a single aggregate object (never a list) → no "empty" branch;
    // a freshly-seeded tenant with all-zero counts is a valid 'ready'.
    const state: KpiState = query.isError ? 'error' : query.isLoading ? 'loading' : 'ready';
    const metrics = query.data;

    return (
        <div data-testid="admin-invitations-overview" data-state={state} aria-busy={state === 'loading'}>
            <div style={filterRowStyle}>
                <SelectFilter
                    id="invitations-overview-campaign"
                    testid="admin-invitations-overview-filter-campaign"
                    label="Campaign"
                    value={campaignId}
                    onChange={setCampaignId}
                    allLabel="All campaigns"
                    options={(campaignsQuery.data ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
                />
                <SelectFilter
                    id="invitations-overview-since"
                    testid="admin-invitations-overview-filter-since"
                    label="Window"
                    value={since}
                    onChange={setSince}
                    allLabel="All time"
                    options={SINCE_OPTIONS}
                />
            </div>

            {state === 'error' && (
                <p
                    data-testid="admin-invitations-overview-error"
                    role="alert"
                    style={{ color: 'var(--danger-fg, #f87171)', fontSize: 13, margin: '8px 0 16px', maxWidth: 640 }}
                >
                    Couldn't load invitation metrics — check that you're signed in and the invitations API
                    (<code style={{ fontFamily: 'var(--font-mono)' }}>/api/admin/invitations/metrics</code>) is
                    reachable.
                </p>
            )}

            <div
                role="list"
                aria-label="Invite funnel KPIs"
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))',
                    gap: 12,
                    marginBottom: 20,
                }}
            >
                {KPIS.map((kpi) => (
                    <div role="listitem" key={kpi.slug}>
                        <KpiCard
                            slug={kpi.slug}
                            icon={kpi.icon}
                            label={kpi.label}
                            value={kpi.value(metrics)}
                            hint={kpi.hint}
                            state={state}
                        />
                    </div>
                ))}
            </div>

            <FunnelBars metrics={metrics} state={state} />
        </div>
    );
}

interface FunnelBarsProps {
    metrics: InviteMetrics | undefined;
    state: KpiState;
}

function FunnelBars({ metrics, state }: FunnelBarsProps) {
    if (state !== 'ready' || !metrics) return null;

    const stages: Array<{ key: string; label: string; value: number; color: string }> = [
        { key: 'codes-issued', label: 'Codes issued', value: metrics.codes_issued, color: '#6366f1' },
        { key: 'invites-sent', label: 'Invites sent', value: metrics.invites_sent, color: '#3b82f6' },
        { key: 'invites-accepted', label: 'Invites accepted', value: metrics.invites_accepted, color: '#0ea5e9' },
        { key: 'redemptions', label: 'Redemptions', value: metrics.redemptions, color: '#10b981' },
    ];

    // R14: guard the denominator. All-zero tenant → every bar is 0% wide,
    // never NaN (value / 0). Math.max over a fixed finite list (not an empty
    // spread) is safe by construction.
    const max = Math.max(0, ...stages.map((s) => (Number.isFinite(s.value) ? s.value : 0)));

    return (
        <section data-testid="admin-invitations-funnel" aria-label="Acquisition funnel" style={{ maxWidth: 560 }}>
            <h2 style={{ fontSize: 13, fontWeight: 600, color: 'var(--fg-1)', margin: '0 0 10px' }}>Funnel</h2>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                {stages.map((stage) => {
                    const pct = max > 0 ? Math.round((stage.value / max) * 100) : 0;
                    return (
                        <div key={stage.key} data-testid={`admin-invitations-funnel-${stage.key}`}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11.5, color: 'var(--fg-2)', marginBottom: 3 }}>
                                <span>{stage.label}</span>
                                <span style={{ fontVariantNumeric: 'tabular-nums', color: 'var(--fg-1)' }}>
                                    {formatCompact(stage.value)}
                                </span>
                            </div>
                            <div
                                role="progressbar"
                                aria-valuenow={pct}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-label={`${stage.label}: ${pct}% of peak`}
                                style={{ height: 8, borderRadius: 99, background: 'var(--bg-3, rgba(255,255,255,.06))', overflow: 'hidden' }}
                            >
                                <div style={{ width: `${pct}%`, height: '100%', background: stage.color, transition: 'width .3s' }} />
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
