import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { meDashboardApi, ME_COACHING_QUERY_KEY } from './me-dashboard.api';

/**
 * v8.18/W4 — the AI coaching card on the "My KB" dashboard. Renders the
 * per-user coaching narrative (headline, strengths, growth, next steps,
 * summary) plus the AI-awarded titles.
 *
 * Self-scoped (GET /api/me/coaching). When the backend reports
 * `available:false` the insight has not been computed yet for this user —
 * we render a friendly empty state (NOT null) so the user knows the
 * feature exists. API errors surface in the DOM (R14). a11y per R15.
 */
export function CoachingCard(): ReactNode {
    const query = useQuery({
        queryKey: ME_COACHING_QUERY_KEY,
        queryFn: () => meDashboardApi.coaching(),
        refetchOnWindowFocus: false,
        staleTime: 60_000,
    });

    // Surface a real backend/API failure instead of swallowing it (R14).
    if (query.isError) {
        return (
            <section data-testid="me-coaching" data-state="error" role="alert" style={{ marginTop: 20 }}>
                <h3 style={{ marginTop: 0 }}>AI coaching</h3>
                Could not load your coaching insights.{' '}
                <button type="button" data-testid="me-coaching-retry" onClick={() => void query.refetch()}>
                    Retry
                </button>
            </section>
        );
    }

    if (query.isLoading) {
        return (
            <section data-testid="me-coaching" data-state="loading" aria-busy="true" style={{ marginTop: 20 }}>
                <h3 style={{ marginTop: 0 }}>AI coaching</h3>
                <p style={{ color: 'var(--fg-3)' }}>Loading your coaching insights…</p>
            </section>
        );
    }

    const insight = query.data?.insight;
    if (!query.data?.available || !insight) {
        return (
            <section data-testid="me-coaching" data-state="empty" aria-busy={query.isFetching} style={{ marginTop: 20 }}>
                <h3 style={{ marginTop: 0 }}>AI coaching</h3>
                <p data-testid="me-coaching-empty" style={{ color: 'var(--fg-3)' }}>
                    No coaching yet — keep contributing and your personalised AI insights will appear here.
                </p>
            </section>
        );
    }

    const { narrative, titles } = insight;
    // Belt-and-braces: the backend guarantees these arrays exist (it merges the
    // LLM narrative over a deterministic shape), but default here too so a future
    // contract drift can never white-screen the card (R14).
    const strengths = narrative.strengths ?? [];
    const growth = narrative.growth ?? [];
    const nextSteps = narrative.next_steps ?? [];
    const titleList = titles ?? [];

    return (
        <section data-testid="me-coaching" data-state="ready" aria-busy={query.isFetching} style={{ marginTop: 20 }}>
            <h3 style={{ marginTop: 0 }}>AI coaching</h3>
            <p data-testid="me-coaching-headline" style={{ fontWeight: 600, fontSize: 16 }}>
                {narrative.headline}
            </p>
            <p style={{ color: 'var(--fg-2)' }}>{narrative.summary}</p>

            {strengths.length > 0 && (
                <div data-testid="me-coaching-strengths">
                    <h4>Strengths</h4>
                    <ul>
                        {strengths.map((s, i) => (
                            <li key={`strength-${i}`}>{s}</li>
                        ))}
                    </ul>
                </div>
            )}

            {growth.length > 0 && (
                <div data-testid="me-coaching-growth">
                    <h4>Growth areas</h4>
                    <ul>
                        {growth.map((g, i) => (
                            <li key={`growth-${i}`}>{g}</li>
                        ))}
                    </ul>
                </div>
            )}

            {nextSteps.length > 0 && (
                <div data-testid="me-coaching-next-steps">
                    <h4>Next steps</h4>
                    <ul>
                        {nextSteps.map((n, i) => (
                            <li key={`next-${i}`}>{n}</li>
                        ))}
                    </ul>
                </div>
            )}

            {titleList.length > 0 && (
                <div data-testid="me-coaching-titles" style={{ display: 'flex', flexWrap: 'wrap', gap: 12, marginTop: 12 }}>
                    {titleList.map((t) => (
                        <div
                            key={t.key}
                            data-testid={`me-coaching-title-${t.key}`}
                            title={t.reason}
                            style={{
                                border: '1px solid var(--hairline)',
                                borderRadius: 12,
                                padding: '10px 14px',
                                minWidth: 140,
                                textAlign: 'center',
                            }}
                        >
                            <div style={{ fontSize: 24 }} aria-hidden="true">{t.icon}</div>
                            <div style={{ fontWeight: 600 }}>{t.label}</div>
                            <div style={{ fontSize: 12, color: 'var(--fg-3)' }}>{t.reason}</div>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
