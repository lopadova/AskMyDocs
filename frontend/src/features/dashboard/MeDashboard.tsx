import { useUiTranslation } from "../../lib/ui-locale";
import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button } from '../../components/Button';
import { EditorialArtwork } from '../../components/editorial/EditorialArtwork';
import type { Artwork } from '../../components/shell/navigation';
import { meDashboardApi, ME_DASHBOARD_QUERY_KEY } from './me-dashboard.api';
import { MeBadges } from './MeBadges';
import { CoachingCard } from './CoachingCard';
import './editorial-dashboard.css';

type PersonalKpiProps = {
    slug: string;
    artwork: Artwork;
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    state: 'loading' | 'ready' | 'error';
};

function PersonalKpi({ slug, artwork, label, value, hint, state }: PersonalKpiProps) {
    return (
        <div data-testid={`kpi-card-${slug}`} data-state={state} className="personal-kpi editorial-surface">
            <EditorialArtwork artwork={artwork} className="editorial-surface-art" resolution="small" />
            <div className="personal-kpi-label">{label}</div>
            <div className="personal-kpi-value">
                {state === 'loading' ? <span className="shimmer">…</span> : value}
            </div>
            {hint ? <div className="personal-kpi-hint">{hint}</div> : null}
        </div>
    );
}

/**
 * v8.15/W4.2 — the personal "your KB" dashboard: your contribution score, rank,
 * authored docs, questions asked, active days, and your own docs needing review.
 * Personal editorial KPI tiles preserve R11 testids and explicit data-state.
 */
export function MeDashboard(): ReactNode {
    const { t: translateInterface } = useUiTranslation();
    const query = useQuery({
        queryKey: ME_DASHBOARD_QUERY_KEY,
        queryFn: () => meDashboardApi.load(30),
        refetchOnWindowFocus: false,
        staleTime: 60_000,
    });

    const dataState = query.isError ? 'error' : query.isLoading ? 'loading' : 'ready';
    const kpi = query.isError ? 'error' : query.isLoading ? 'loading' : 'ready';
    const d = query.data?.dashboard;

    return (
        <section data-testid="me-dashboard" data-state={dataState} aria-busy={query.isFetching} className="personal-dashboard">
            <h2 className="personal-dashboard-title">{translateInterface("Your knowledge base")}</h2>

            {dataState === 'error' && (
                <div data-testid="me-dashboard-error" role="alert" className="personal-dashboard-notice">
                    {translateInterface('Could not load your dashboard.')}{' '}
                    <Button size="sm" data-testid="me-dashboard-retry" onClick={() => void query.refetch()} busy={query.isFetching}>{translateInterface("Retry")}</Button>
                </div>
            )}

            <div data-testid="me-dashboard-kpis" className="personal-kpi-grid">
                <PersonalKpi slug="me-score" artwork="knowledge" label={translateInterface("Contribution score")} value={d?.contributions.score ?? '—'} state={kpi} />
                <PersonalKpi slug="me-rank" artwork="observatory" label={translateInterface("Your rank")} value={d?.rank ?? '—'} hint={d?.rank ? '#' + d.rank : translateInterface("no activity yet")} state={kpi} />
                <PersonalKpi slug="me-authored" artwork="archive" label={translateInterface("Docs authored")} value={d?.authored_docs ?? '—'} state={kpi} />
                <PersonalKpi slug="me-questions" artwork="dialogue" label={translateInterface("Questions asked")} value={d?.questions_asked ?? '—'} state={kpi} />
                <PersonalKpi slug="me-active-days" artwork="processes" label={translateInterface("Active days")} value={d?.active_days ?? '—'} hint={translateInterface('{count}-day window', { count: d?.window_days ?? 30 })} state={kpi} />
                <PersonalKpi slug="me-citations" artwork="connections" label={translateInterface("Your impact (citations)")} value={d?.contributions.citations ?? '—'} state={kpi} />
            </div>

            {dataState === 'ready' && d && (
                <div data-testid="me-dashboard-review" className="personal-review editorial-surface">
                    <EditorialArtwork artwork="archive" className="editorial-surface-art" resolution="small" />
                    <h3>{translateInterface("Your docs needing review")}</h3>
                    {d.docs_needing_review.length === 0 ? (
                        <p data-testid="me-dashboard-review-empty">{translateInterface("Nothing needs your attention right now.")}</p>
                    ) : (
                        <ul className="personal-review-list">
                            {d.docs_needing_review.map((doc, i) => (
                                <li key={doc.slug ?? `${doc.title}-${i}`} data-testid={`me-dashboard-review-item-${i}`}>
                                    {doc.title} <em>{translateInterface("(debt") + " "}{doc.debt_score})</em>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            <MeBadges />
            <CoachingCard />
        </section>
    );
}
