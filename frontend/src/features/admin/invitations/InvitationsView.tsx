/*
 * InvitationsView — native host landing for the
 * `padosoft/laravel-invitations-admin` panel.
 *
 * The panel is a self-contained, prebuilt React SPA the package serves over a
 * gated Blade route at `/admin/invitations` (its own chrome — sidebar + topbar).
 * Like the Flow cockpit (see FlowsView), it is NOT cross-mounted as a tree
 * inside the host SPA: embedding its full chrome in the host content area would
 * nest a second UI. Per the agreed sister-admin strategy it opens STANDALONE in
 * a new tab where its own chrome belongs.
 *
 * The host page itself stays native + center-only: live invite-funnel KPIs read
 * from the SAME core API the panel uses (`/api/admin/invitations/metrics`, the
 * route PR #363 mounted), plus a one-click launch to the panel. No iframe → no
 * nested chrome. Both the panel mount and this KPI read are gated server-side by
 * `can:manageInvitations`; a viewer/non-manager 403s on the metrics fetch (shown
 * as the error state) and the nav item is hidden for them.
 */
import { useEffect, useState } from 'react';
import { Icon } from '../../../components/Icons';
import { useTeamStore } from '../../../lib/team-store';

const INVITATIONS_ADMIN_BASE_URL = '/admin/invitations';
const INVITATIONS_METRICS_URL = '/api/admin/invitations/metrics';

interface InviteMetrics {
    codesIssued: number;
    redemptions: number;
    kFactor: number;
}

export function InvitationsView() {
    const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
    const [metrics, setMetrics] = useState<InviteMetrics | null>(null);

    useEffect(() => {
        let active = true;
        const controller = new AbortController();
        const id = window.setTimeout(() => controller.abort(), 10_000);

        // Raw fetch (not the shared axios client) → replicate lib/api.ts's team
        // header by hand, INCLUDING the `default` sentinel skip (api.ts omits
        // X-Tenant-Id for `default` so sister-package mounts take their
        // host-config fallback instead of 404ing). Same pattern as FlowsView.
        const team = useTeamStore.getState().currentTeam;
        void fetch(INVITATIONS_METRICS_URL, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(team !== null && team !== 'default' ? { 'X-Tenant-Id': team } : {}),
            },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!active) return;
                if (!response.ok) {
                    setState('error');
                    return;
                }
                const body = (await response.json().catch(() => null)) as { data?: Record<string, unknown> } | null;
                const data = body?.data ?? null;
                // R14: validate the SHAPE, not just non-null. A non-JSON 200, a
                // renamed field, or a string where a number is expected would
                // otherwise render NaN KPIs that look like valid data. Require
                // every counter to be a finite number before trusting it.
                if (
                    data === null ||
                    typeof data.codes_issued !== 'number' ||
                    !Number.isFinite(data.codes_issued) ||
                    typeof data.redemptions !== 'number' ||
                    !Number.isFinite(data.redemptions) ||
                    typeof data.k_factor !== 'number' ||
                    !Number.isFinite(data.k_factor)
                ) {
                    setState('error');
                    return;
                }
                setMetrics({
                    codesIssued: data.codes_issued,
                    redemptions: data.redemptions,
                    kFactor: data.k_factor,
                });
                setState('ready');
            })
            .catch(() => active && setState('error'))
            .finally(() => window.clearTimeout(id));

        return () => {
            active = false;
            controller.abort();
            window.clearTimeout(id);
        };
    }, []);

    return (
        <div
            data-testid="admin-invitations-host"
            data-state={state}
            style={{
                flex: 1,
                display: 'flex',
                flexDirection: 'column',
                background: 'var(--bg-0)',
                color: 'var(--fg-1)',
                fontFamily: 'var(--font-sans)',
                overflow: 'auto',
            }}
        >
            <header
                style={{
                    padding: '14px 22px',
                    borderBottom: '1px solid var(--border-1)',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 12,
                }}
            >
                <h1 style={{ margin: 0, fontSize: 18, fontWeight: 700, letterSpacing: '-0.01em' }}>Invitations</h1>
                <span
                    style={{
                        padding: '2px 8px',
                        borderRadius: 999,
                        background: 'rgba(245,158,11,0.18)',
                        color: '#fbbf24',
                        fontSize: 11.5,
                        fontWeight: 600,
                        letterSpacing: '0.04em',
                        textTransform: 'uppercase',
                    }}
                >
                    padosoft/laravel-invitations-admin
                </span>
                <span style={{ flex: 1 }} />
                <a
                    href={INVITATIONS_ADMIN_BASE_URL}
                    target="_blank"
                    rel="noopener noreferrer"
                    data-testid="admin-invitations-open-panel"
                    className="focus-ring"
                    style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 6,
                        fontSize: 12.5,
                        fontWeight: 600,
                        color: 'var(--fg-0)',
                        textDecoration: 'none',
                        padding: '7px 14px',
                        borderRadius: 8,
                        background: 'var(--grad-accent)',
                    }}
                >
                    Open Invitations admin
                    <Icon.Share size={13} />
                </a>
            </header>

            <p style={{ margin: 0, padding: '12px 22px 0', color: 'var(--fg-2)', fontSize: 13, maxWidth: 760 }}>
                The full invitations panel (campaigns, codes, invitations, referrals, rewards, waitlist, anti-abuse
                review, settings) opens in a new tab. Live funnel below is read from{' '}
                <code style={{ fontFamily: 'var(--font-mono)' }}>/api/admin/invitations/metrics</code>.
            </p>

            {state === 'error' && (
                <div
                    data-testid="admin-invitations-error"
                    role="alert"
                    style={{ margin: '16px 22px', color: 'var(--danger-fg)', fontSize: 13, maxWidth: 640 }}
                >
                    The invitations panel is unavailable. Confirm <code>INVITATIONS_ADMIN_ENABLED=true</code> in the
                    host environment and run <code>php artisan config:clear</code>. You can still try{' '}
                    <strong>Open Invitations admin</strong> above.
                </div>
            )}

            {state === 'loading' && (
                <div data-testid="admin-invitations-loading" style={{ padding: 22, color: 'var(--fg-2)', fontSize: 13 }}>
                    <span className="shimmer" style={{ padding: '6px 18px', borderRadius: 8 }}>
                        Loading invite funnel…
                    </span>
                </div>
            )}

            {state === 'ready' && (
                <div
                    role="list"
                    aria-label="Invite funnel"
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))',
                        gap: 14,
                        padding: '18px 22px 4px',
                    }}
                >
                    {[
                        { key: 'codes', label: 'Codes issued', value: metrics?.codesIssued ?? 0 },
                        { key: 'redemptions', label: 'Redemptions', value: metrics?.redemptions ?? 0 },
                        { key: 'k-factor', label: 'K-factor', value: metrics?.kFactor ?? 0 },
                    ].map((kpi) => (
                        <article
                            key={kpi.key}
                            role="listitem"
                            data-testid={`admin-invitations-kpi-${kpi.key}`}
                            style={{
                                border: '1px solid var(--border-1)',
                                borderRadius: 12,
                                padding: 16,
                                background: 'var(--bg-1)',
                            }}
                        >
                            <div style={{ fontSize: 12, color: 'var(--fg-3)' }}>{kpi.label}</div>
                            <div style={{ fontSize: 26, fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
                                {kpi.value}
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </div>
    );
}
