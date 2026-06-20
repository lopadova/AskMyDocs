import type { ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '../../../lib/auth-store';
import {
    engagementApi,
    GAMIFICATION_INSIGHTS_QUERY_KEY,
} from './engagement.api';

/**
 * v8.18/W4 — admin AI gamification insights: the project/tenant health
 * narrative (headline, summary, actions, advice) computed by the engagement
 * insights pipeline. Defaults to the tenant scope.
 *
 * The "Rigenera" (regenerate) button is super-admin only — the backend
 * (POST /api/admin/engagement/insights/regenerate) returns 403 for plain
 * admins. We gate the button on the client role signal (useAuthStore.roles)
 * so admins never see a button that would 403; a stale client role still
 * degrades safely because the backend re-enforces the check.
 *
 * R11 testids, explicit data-state. Empty state when `available:false`
 * (data-state="empty", NOT null) so the feature is discoverable. API errors
 * surface in the DOM (R14). a11y per R15.
 */
export function GamificationInsightsPanel(): ReactNode {
    const qc = useQueryClient();
    const isSuperAdmin = useAuthStore((s) => s.roles).includes('super-admin');

    const query = useQuery({
        queryKey: GAMIFICATION_INSIGHTS_QUERY_KEY,
        queryFn: () => engagementApi.insights('tenant'),
        refetchOnWindowFocus: false,
        staleTime: 30_000,
    });

    const regenerate = useMutation({
        mutationFn: () => engagementApi.regenerateInsights(),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: GAMIFICATION_INSIGHTS_QUERY_KEY });
        },
    });

    const insight = query.data?.insight;
    const dataState = query.isError
        ? 'error'
        : query.isLoading
            ? 'loading'
            : query.data?.available && insight
                ? 'ready'
                : 'empty';

    return (
        <section
            data-testid="admin-gamification-insights"
            data-state={dataState}
            aria-busy={query.isFetching || regenerate.isPending}
            style={{ padding: 24 }}
        >
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
                <h2 style={{ marginTop: 0 }}>AI gamification insights</h2>
                {isSuperAdmin && (
                    <button
                        type="button"
                        data-testid="admin-gamification-regenerate"
                        disabled={regenerate.isPending}
                        aria-busy={regenerate.isPending}
                        onClick={() => regenerate.mutate()}
                    >
                        {regenerate.isPending ? 'Rigenerazione…' : 'Rigenera'}
                    </button>
                )}
            </div>

            {query.isError && (
                <div data-testid="admin-gamification-insights-error" role="alert">
                    Could not load the gamification insights.{' '}
                    <button
                        type="button"
                        data-testid="admin-gamification-insights-retry"
                        onClick={() => void query.refetch()}
                    >
                        Retry
                    </button>
                </div>
            )}

            {regenerate.isError && (
                <div data-testid="admin-gamification-regenerate-error" role="alert">
                    Could not regenerate insights — your account may lack the required permission.
                </div>
            )}

            {dataState === 'loading' && (
                <p style={{ color: 'var(--fg-3)' }}>Loading gamification insights…</p>
            )}

            {dataState === 'empty' && (
                <p data-testid="admin-gamification-insights-empty" style={{ color: 'var(--fg-3)' }}>
                    No insights yet for this tenant. {isSuperAdmin ? 'Use “Rigenera” to compute them.' : 'They will appear once computed.'}
                </p>
            )}

            {dataState === 'ready' && insight && (() => {
                // R14: a wrong TYPE (LLM-persisted string) must never reach .map().
                const actions = Array.isArray(insight.narrative.actions) ? insight.narrative.actions : [];
                const advice = Array.isArray(insight.narrative.advice) ? insight.narrative.advice : [];

                return (
                    <div data-testid="admin-gamification-insights-body">
                        <p data-testid="admin-gamification-insights-headline" style={{ fontWeight: 600, fontSize: 16 }}>
                            {insight.narrative.headline}
                        </p>
                        <p style={{ color: 'var(--fg-2)' }}>{insight.narrative.summary}</p>

                        {actions.length > 0 && (
                            <div data-testid="admin-gamification-insights-actions">
                                <h4>Recommended actions</h4>
                                <ul>
                                    {actions.map((a, i) => (
                                        <li key={`action-${i}`}>{a}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {advice.length > 0 && (
                            <div data-testid="admin-gamification-insights-advice">
                                <h4>Advice</h4>
                                <ul>
                                    {advice.map((a, i) => (
                                        <li key={`advice-${i}`}>{a}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                );
            })()}
        </section>
    );
}
