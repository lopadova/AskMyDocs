import { useUiTranslation } from "../../lib/ui-locale";
import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button } from '../../components/Button';
import { EditorialArtwork } from '../../components/editorial/EditorialArtwork';
import { meDashboardApi, ME_COACHING_QUERY_KEY } from './me-dashboard.api';
import './editorial-dashboard.css';

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
    const { t: translateInterface } = useUiTranslation();
    const query = useQuery({
        queryKey: ME_COACHING_QUERY_KEY,
        queryFn: () => meDashboardApi.coaching(),
        refetchOnWindowFocus: false,
        staleTime: 60_000,
    });

    // Surface a real backend/API failure instead of swallowing it (R14).
    if (query.isError) {
        return (
            <section data-testid="me-coaching" data-state="error" role="alert" className="personal-coaching editorial-surface">
                <EditorialArtwork artwork="community" className="editorial-surface-art" resolution="small" />
                <h3>{translateInterface("AI coaching")}</h3>
                {translateInterface("Could not load your coaching insights.")}{' '}
                <Button size="sm" data-testid="me-coaching-retry" onClick={() => void query.refetch()} busy={query.isFetching}>
                    {translateInterface("Retry")}</Button>
            </section>
        );
    }

    if (query.isLoading) {
        return (
            <section data-testid="me-coaching" data-state="loading" aria-busy="true" className="personal-coaching editorial-surface">
                <EditorialArtwork artwork="community" className="editorial-surface-art" resolution="small" />
                <h3>{translateInterface("AI coaching")}</h3>
                <p className="personal-panel-description">{translateInterface("Loading your coaching insights…")}</p>
            </section>
        );
    }

    const insight = query.data?.insight;
    if (!query.data?.available || !insight) {
        return (
            <section data-testid="me-coaching" data-state="empty" aria-busy={query.isFetching} className="personal-coaching editorial-surface">
                <EditorialArtwork artwork="community" className="editorial-surface-art" resolution="small" />
                <h3>{translateInterface("AI coaching")}</h3>
                <p data-testid="me-coaching-empty" className="personal-panel-description">
                    {translateInterface("No coaching yet — keep contributing and your personalised AI insights will appear here.")}</p>
            </section>
        );
    }

    const { narrative, titles } = insight;
    // Belt-and-braces: the backend type-reconciles the LLM narrative against a
    // deterministic shape, but guard with Array.isArray here too — a wrong TYPE
    // (e.g. an LLM-persisted string) must never reach .map() (R14).
    const strengths = Array.isArray(narrative.strengths) ? narrative.strengths : [];
    const growth = Array.isArray(narrative.growth) ? narrative.growth : [];
    const nextSteps = Array.isArray(narrative.next_steps) ? narrative.next_steps : [];
    const titleList = Array.isArray(titles) ? titles : [];

    return (
        <section data-testid="me-coaching" data-state="ready" aria-busy={query.isFetching} className="personal-coaching editorial-surface">
            <EditorialArtwork artwork="community" className="editorial-surface-art" resolution="small" />
            <h3>{translateInterface("AI coaching")}</h3>
            <p data-testid="me-coaching-headline" className="personal-coaching-headline">
                {narrative.headline}
            </p>
            <p className="personal-panel-description">{narrative.summary}</p>

            <div className="personal-coaching-columns">
                {strengths.length > 0 && (
                    <div data-testid="me-coaching-strengths">
                        <h4>{translateInterface('Strengths')}</h4>
                        <ul>
                            {strengths.map((s, i) => (
                                <li key={`strength-${i}`}>{s}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {growth.length > 0 && (
                    <div data-testid="me-coaching-growth">
                        <h4>{translateInterface('Growth areas')}</h4>
                        <ul>
                            {growth.map((g, i) => (
                                <li key={`growth-${i}`}>{g}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {nextSteps.length > 0 && (
                    <div data-testid="me-coaching-next-steps">
                        <h4>{translateInterface('Next steps')}</h4>
                        <ul>
                            {nextSteps.map((n, i) => (
                                <li key={`next-${i}`}>{n}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            {titleList.length > 0 && (
                <div data-testid="me-coaching-titles" className="personal-coaching-titles">
                    {titleList.map((t) => (
                        <div
                            key={t.key}
                            data-testid={`me-coaching-title-${t.key}`}
                            title={t.reason}
                            className="personal-coaching-title"
                        >
                            <div className="personal-badge-label">{translateInterface(t.label)}</div>
                            <div className="personal-panel-description">{t.reason}</div>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
