import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { KpiCard, type KpiState } from '../admin/dashboard/KpiCard';
import { meDashboardApi, ME_DASHBOARD_QUERY_KEY } from './me-dashboard.api';

/**
 * v8.15/W4.2 — the personal "your KB" dashboard: your contribution score, rank,
 * authored docs, questions asked, active days, and your own docs needing review.
 * Reuses the admin KpiCard; R11 testids, explicit data-state.
 */
export function MeDashboard(): ReactNode {
    const query = useQuery({
        queryKey: ME_DASHBOARD_QUERY_KEY,
        queryFn: () => meDashboardApi.load(30),
        refetchOnWindowFocus: false,
        staleTime: 60_000,
    });

    const dataState = query.isError ? 'error' : query.isLoading ? 'loading' : 'ready';
    const kpi: KpiState = query.isError ? 'error' : query.isLoading ? 'loading' : 'ready';
    const d = query.data?.dashboard;

    return (
        <section data-testid="me-dashboard" data-state={dataState} aria-busy={query.isFetching} style={{ padding: 24 }}>
            <h2 style={{ marginTop: 0 }}>Your knowledge base</h2>

            {dataState === 'error' && (
                <div data-testid="me-dashboard-error" role="alert">
                    Could not load your dashboard.{' '}
                    <button type="button" data-testid="me-dashboard-retry" onClick={() => void query.refetch()}>Retry</button>
                </div>
            )}

            <div
                data-testid="me-dashboard-kpis"
                style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12, marginTop: 12 }}
            >
                <KpiCard slug="me-score" icon="Sparkles" label="Contribution score" value={d?.contributions.score ?? '—'} state={kpi} />
                <KpiCard slug="me-rank" icon="Shield" label="Your rank" value={d?.rank ?? '—'} hint={d?.rank ? '#' + d.rank : 'no activity yet'} state={kpi} />
                <KpiCard slug="me-authored" icon="Book" label="Docs authored" value={d?.authored_docs ?? '—'} state={kpi} />
                <KpiCard slug="me-questions" icon="Chat" label="Questions asked" value={d?.questions_asked ?? '—'} state={kpi} />
                <KpiCard slug="me-active-days" icon="Calendar" label="Active days" value={d?.active_days ?? '—'} hint={`${d?.window_days ?? 30}-day window`} state={kpi} />
                <KpiCard slug="me-citations" icon="Eye" label="Your impact (citations)" value={d?.contributions.citations ?? '—'} state={kpi} />
            </div>

            {dataState === 'ready' && d && (
                <div data-testid="me-dashboard-review" style={{ marginTop: 20 }}>
                    <h3>Your docs needing review</h3>
                    {d.docs_needing_review.length === 0 ? (
                        <p data-testid="me-dashboard-review-empty">Nothing needs your attention right now. 🎉</p>
                    ) : (
                        <ul>
                            {d.docs_needing_review.map((doc, i) => (
                                <li key={doc.slug ?? `${doc.title}-${i}`} data-testid={`me-dashboard-review-item-${i}`}>
                                    {doc.title} <em>(debt {doc.debt_score})</em>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </section>
    );
}
