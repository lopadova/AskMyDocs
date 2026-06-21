/*
 * FlowsView — native host landing for the `padosoft/laravel-flow-admin`
 * cockpit.
 *
 * Phase 2 of the unified-admin work. The cockpit is a server-rendered
 * Blade + Alpine application with its OWN full chrome (sidebar + topbar +
 * command palette). Embedding it in an iframe nested that whole second
 * UI inside the host content area — the "full app in a small frame" Lorenzo
 * flagged. Because it is NOT a React SPA there is no tree to cross-mount and
 * strip to content-only, so per the agreed strategy the rich cockpit opens
 * STANDALONE in a new tab (`target=_blank`), where its own chrome belongs.
 *
 * The host page itself stays native + center-only: live KPIs from
 * `/admin/flows/api/live` and quick-launch links to each cockpit section,
 * so operators get an at-a-glance view without leaving AskMyDocs and one
 * click to the full tool. No iframe → no nested chrome.
 */
import { useEffect, useState } from 'react';
import { Icon } from '../../../components/Icons';
import { useTeamStore } from '../../../lib/team-store';

const FLOW_ADMIN_BASE_URL = '/admin/flows';
const FLOW_ADMIN_LIVE_URL = `${FLOW_ADMIN_BASE_URL}/api/live`;

interface FlowLive {
    totalRuns: number;
    failedRuns: number;
}

// The cockpit's own sections — surfaced here as quick-launch links so the
// host page mirrors the tool's menu without embedding its chrome.
const COCKPIT_SECTIONS: Array<{ id: string; label: string; path: string }> = [
    { id: 'overview', label: 'Overview', path: '' },
    { id: 'runs', label: 'Runs', path: '/runs' },
    { id: 'approvals', label: 'Approvals', path: '/approvals' },
    { id: 'outbox', label: 'Outbox', path: '/outbox' },
    { id: 'definitions', label: 'Definitions', path: '/definitions' },
    { id: 'settings', label: 'Settings', path: '/settings' },
];

export function FlowsView() {
    const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
    const [live, setLive] = useState<FlowLive | null>(null);

    useEffect(() => {
        let active = true;
        const controller = new AbortController();
        const id = window.setTimeout(() => controller.abort(), 10_000);

        // Raw fetch (not the shared axios client) → the team header from
        // lib/api.ts's interceptor must be replicated by hand, like the
        // chat SSE transport does — INCLUDING the `default` sentinel skip
        // (api.ts omits X-Tenant-Id for `default` so sister-package mounts
        // take their host-config fallback instead of 404ing).
        const team = useTeamStore.getState().currentTeam;
        void fetch(FLOW_ADMIN_LIVE_URL, {
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
                const body = (await response.json().catch(() => null)) as Partial<FlowLive> | null;
                // R14: validate the SHAPE, not just non-null. A non-JSON 200, a
                // renamed field, or a string where a number is expected would
                // otherwise render NaN / 0-0-0 KPIs that look like valid data.
                // Require both counters to be finite numbers before trusting it.
                if (
                    body === null ||
                    typeof body.totalRuns !== 'number' ||
                    !Number.isFinite(body.totalRuns) ||
                    typeof body.failedRuns !== 'number' ||
                    !Number.isFinite(body.failedRuns)
                ) {
                    setState('error');
                    return;
                }
                setLive({ totalRuns: body.totalRuns, failedRuns: body.failedRuns });
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

    const succeeded = live ? Math.max(0, live.totalRuns - live.failedRuns) : 0;

    return (
        <div
            data-testid="admin-flows-host"
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
                <h1 style={{ margin: 0, fontSize: 18, fontWeight: 700, letterSpacing: '-0.01em' }}>Flows</h1>
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
                    padosoft/laravel-flow-admin
                </span>
                <span style={{ flex: 1 }} />
                <a
                    href={FLOW_ADMIN_BASE_URL}
                    target="_blank"
                    rel="noopener noreferrer"
                    data-testid="admin-flows-open-cockpit"
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
                    Open Flow cockpit
                    <Icon.Share size={13} />
                </a>
            </header>

            <p style={{ margin: 0, padding: '12px 22px 0', color: 'var(--fg-2)', fontSize: 13, maxWidth: 760 }}>
                The full Flow cockpit (runs, approvals, webhook outbox, definitions) opens in a new tab. Live
                throughput below is read from <code style={{ fontFamily: 'var(--font-mono)' }}>/admin/flows/api/live</code>.
            </p>

            {state === 'error' && (
                <div
                    data-testid="admin-flows-error"
                    role="alert"
                    style={{ margin: '16px 22px', color: 'var(--danger-fg)', fontSize: 13, maxWidth: 620 }}
                >
                    The Flow cockpit is unavailable. Confirm <code>FLOW_ADMIN_ENABLED=true</code> in the host
                    environment and run <code>php artisan config:clear</code>. You can still try{' '}
                    <strong>Open Flow cockpit</strong> above.
                </div>
            )}

            {state === 'loading' && (
                <div data-testid="admin-flows-loading" style={{ padding: 22, color: 'var(--fg-2)', fontSize: 13 }}>
                    <span className="shimmer" style={{ padding: '6px 18px', borderRadius: 8 }}>
                        Loading Flow throughput…
                    </span>
                </div>
            )}

            {state === 'ready' && (
                <div
                    role="list"
                    aria-label="Flow throughput"
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))',
                        gap: 14,
                        padding: '18px 22px 4px',
                    }}
                >
                    {[
                        { key: 'total', label: 'Total runs', value: live?.totalRuns ?? 0 },
                        { key: 'succeeded', label: 'Succeeded', value: succeeded },
                        { key: 'failed', label: 'Failed', value: live?.failedRuns ?? 0 },
                    ].map((kpi) => (
                        <article
                            key={kpi.key}
                            role="listitem"
                            data-testid={`admin-flows-kpi-${kpi.key}`}
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

            <div style={{ padding: '14px 22px 24px' }}>
                <div style={{ fontSize: 12, color: 'var(--fg-3)', marginBottom: 8 }}>Cockpit sections</div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                    {COCKPIT_SECTIONS.map((s) => (
                        <a
                            key={s.id}
                            href={`${FLOW_ADMIN_BASE_URL}${s.path}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            data-testid={`admin-flows-section-${s.id}`}
                            className="focus-ring"
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                fontSize: 12.5,
                                color: 'var(--fg-2)',
                                textDecoration: 'none',
                                padding: '6px 12px',
                                border: '1px solid var(--border-2)',
                                borderRadius: 8,
                            }}
                        >
                            {s.label}
                            <Icon.Share size={11} />
                        </a>
                    ))}
                </div>
            </div>
        </div>
    );
}
