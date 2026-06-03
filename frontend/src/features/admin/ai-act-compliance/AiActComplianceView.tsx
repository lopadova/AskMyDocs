import { useQuery } from '@tanstack/react-query';

import { AdminShell } from '../shell/AdminShell';
import { AI_ACT_DOMAINS, getAiActOverview, type AiActDomainResult } from './ai-act.api';

/**
 * AI Act compliance admin landing — NATIVE live overview.
 *
 * v6.0 shipped this as an iframe cross-mount of the standalone
 * `padosoft/laravel-ai-act-compliance-admin` SPA, but that package is a
 * frontend-only prototype (no Laravel routes / no servable bundle), and the
 * host `/admin/ai-act-compliance/{any?}` placeholder route redirected the
 * iframe target back into the host SPA — which re-rendered this view's
 * iframe, recursing indefinitely. The recursion was caught by live
 * browser verification (the page nested the whole app inside itself).
 *
 * This native panel reads the REAL compliance data the core
 * `padosoft/laravel-ai-act-compliance` package serves under
 * `/api/admin/ai-act-compliance/*` (incidents, DSAR, consent, bias,
 * attestations, human-reviews) — no iframe, no recursion, live counts +
 * status tallies, with explicit loading / error / empty states (R14).
 */
export function AiActComplianceView() {
    const query = useQuery({
        queryKey: ['ai-act-overview'],
        queryFn: getAiActOverview,
        staleTime: 30_000,
    });

    const byKey = new Map<string, AiActDomainResult>((query.data ?? []).map((d) => [d.key, d]));
    const state = query.isLoading ? 'loading' : query.isError ? 'error' : query.data ? 'ready' : 'empty';

    return (
        <AdminShell section="ai-act-compliance">
            <section
                data-testid="admin-ai-act-compliance"
                data-state={state}
                aria-busy={query.isLoading}
                aria-labelledby="admin-ai-act-compliance-title"
                style={{
                    flex: 1,
                    display: 'flex',
                    flexDirection: 'column',
                    minHeight: 0,
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
                    <h1
                        id="admin-ai-act-compliance-title"
                        data-testid="admin-ai-act-compliance-title"
                        style={{ margin: 0, fontSize: 18, fontWeight: 700, letterSpacing: '-0.01em' }}
                    >
                        AI Act compliance
                    </h1>
                    <span
                        data-testid="admin-ai-act-compliance-source"
                        style={{
                            padding: '2px 8px',
                            borderRadius: 999,
                            background: 'rgba(59,130,246,0.18)',
                            color: '#93c5fd',
                            fontSize: 11.5,
                            fontWeight: 600,
                            letterSpacing: '0.04em',
                            textTransform: 'uppercase',
                        }}
                    >
                        padosoft/laravel-ai-act-compliance
                    </span>
                    <span style={{ flex: 1 }} />
                    <button
                        type="button"
                        data-testid="admin-ai-act-compliance-refresh"
                        onClick={() => query.refetch()}
                        disabled={query.isFetching}
                        className="focus-ring"
                        style={{
                            fontSize: 12.5,
                            color: 'var(--fg-2)',
                            background: 'transparent',
                            padding: '6px 12px',
                            border: '1px solid var(--border-2)',
                            borderRadius: 8,
                            cursor: query.isFetching ? 'default' : 'pointer',
                            opacity: query.isFetching ? 0.6 : 1,
                        }}
                    >
                        {query.isFetching ? 'Refreshing…' : 'Refresh'}
                    </button>
                </header>

                <p style={{ margin: 0, padding: '12px 22px 0', color: 'var(--fg-2)', fontSize: 13, maxWidth: 760 }}>
                    Live counts across the EU AI Act compliance registers. Records are created and
                    transitioned through the <code style={{ fontFamily: 'var(--font-mono)' }}>/api/admin/ai-act-compliance/*</code>{' '}
                    endpoints (DSAR intake, incident reporting, consent grants, bias capture, human-review queue).
                </p>

                {query.isError && (
                    <div
                        data-testid="admin-ai-act-compliance-error"
                        role="alert"
                        style={{ margin: '16px 22px', color: 'var(--danger-fg)', fontSize: 13 }}
                    >
                        Failed to load AI Act compliance data. Confirm the{' '}
                        <code style={{ fontFamily: 'var(--font-mono)' }}>padosoft/laravel-ai-act-compliance</code>{' '}
                        package is installed and you hold the <code>viewAiActCompliance</code> permission.
                    </div>
                )}

                <div
                    role="list"
                    aria-label="Compliance registers"
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))',
                        gap: 14,
                        padding: 22,
                    }}
                >
                    {AI_ACT_DOMAINS.map((domain) => {
                        const result = byKey.get(domain.key);
                        const statusEntries = result ? Object.entries(result.statuses) : [];
                        return (
                            <article
                                key={domain.key}
                                role="listitem"
                                data-testid={`admin-ai-act-card-${domain.key}`}
                                style={{
                                    border: '1px solid var(--border-1)',
                                    borderRadius: 12,
                                    padding: 16,
                                    background: 'var(--bg-1)',
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: 8,
                                    minHeight: 120,
                                }}
                            >
                                <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 8 }}>
                                    <h2 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{domain.label}</h2>
                                    <span
                                        data-testid={`admin-ai-act-count-${domain.key}`}
                                        style={{ fontSize: 22, fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}
                                    >
                                        {query.isLoading ? '—' : (result?.count ?? 0)}
                                    </span>
                                </div>
                                <p style={{ margin: 0, color: 'var(--fg-3)', fontSize: 12 }}>{domain.description}</p>
                                {!query.isLoading && statusEntries.length > 0 && (
                                    <ul
                                        style={{
                                            listStyle: 'none',
                                            margin: '4px 0 0',
                                            padding: 0,
                                            display: 'flex',
                                            flexWrap: 'wrap',
                                            gap: 6,
                                        }}
                                    >
                                        {statusEntries.map(([status, n]) => (
                                            <li
                                                key={status}
                                                style={{
                                                    fontSize: 11,
                                                    padding: '2px 8px',
                                                    borderRadius: 999,
                                                    background: 'var(--bg-3)',
                                                    color: 'var(--fg-2)',
                                                    fontFamily: 'var(--font-mono)',
                                                }}
                                            >
                                                {status}: {n}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {!query.isLoading && result?.count === 0 && (
                                    <span style={{ marginTop: 'auto', fontSize: 11.5, color: 'var(--fg-3)' }}>
                                        None recorded yet.
                                    </span>
                                )}
                            </article>
                        );
                    })}
                </div>
            </section>
        </AdminShell>
    );
}
