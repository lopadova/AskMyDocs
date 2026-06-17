import { Suspense, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { KpiCard, type KpiState } from '../dashboard/KpiCard';
import { ChartCard, ChartFallback, EmptyChart } from '../dashboard/ChartCard';
import { LazyAreaChartBody } from '../dashboard/LazyRecharts';
import {
    engagementApi,
    ENGAGEMENT_LEADERBOARD_QUERY_KEY,
    ENGAGEMENT_SERIES_QUERY_KEY,
    ENGAGEMENT_SUMMARY_QUERY_KEY,
} from './engagement.api';

/**
 * v8.15/W4.2 — admin engagement analytics: headline KPIs, the contributor
 * leaderboard, and a contributor trend chart. Reuses KpiCard + ChartCard +
 * the lazy recharts area body. R11 testids, explicit states.
 */
export function EngagementPanel(): ReactNode {
    const summary = useQuery({ queryKey: ENGAGEMENT_SUMMARY_QUERY_KEY, queryFn: () => engagementApi.summary(), refetchOnWindowFocus: false, staleTime: 30_000 });
    const board = useQuery({ queryKey: ENGAGEMENT_LEADERBOARD_QUERY_KEY, queryFn: () => engagementApi.leaderboard(30, 10), refetchOnWindowFocus: false, staleTime: 30_000 });
    const series = useQuery({ queryKey: ENGAGEMENT_SERIES_QUERY_KEY, queryFn: () => engagementApi.series(8), refetchOnWindowFocus: false, staleTime: 30_000 });

    const kpiState: KpiState = summary.isError ? 'error' : summary.isLoading ? 'loading' : 'ready';
    const m = (summary.data?.metrics ?? {}) as Record<string, unknown>;
    const num = (v: unknown): ReactNode => (v === null || v === undefined ? '—' : String(v));

    const trend = (series.data?.series ?? []).map((p) => ({ date: p.date, count: p.contributors }));
    const trendHasData = trend.some((r) => r.count > 0);
    const trendState = series.isError ? 'error' : series.isLoading ? 'loading' : trendHasData ? 'ready' : 'empty';

    const overall = summary.isError || board.isError || series.isError
        ? 'error'
        : summary.isLoading || board.isLoading || series.isLoading
            ? 'loading'
            : 'ready';

    return (
        <section
            data-testid="admin-engagement"
            data-state={overall}
            aria-busy={summary.isFetching || board.isFetching || series.isFetching}
            style={{ padding: 24 }}
        >
            <h2 style={{ marginTop: 0 }}>Engagement</h2>

            {(summary.isError || series.isError) && (
                <div data-testid="admin-engagement-error" role="alert">
                    Some engagement data could not be loaded.{' '}
                    <button
                        type="button"
                        data-testid="admin-engagement-retry"
                        onClick={() => {
                            void summary.refetch();
                            void series.refetch();
                        }}
                    >
                        Retry
                    </button>
                </div>
            )}

            <div
                data-testid="admin-engagement-kpis"
                style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12 }}
            >
                <KpiCard slug="eng-contributors" icon="Users" label="Contributors" value={num(m.contributors)} state={kpiState} />
                <KpiCard slug="eng-new" icon="Book" label="New docs" value={num(m.new_docs)} state={kpiState} />
                <KpiCard slug="eng-promoted" icon="Sparkles" label="Promoted" value={num(m.promoted_docs)} state={kpiState} />
                <KpiCard slug="eng-coverage" icon="Shield" label="Canonical coverage" value={m.canonical_coverage_pct === null || m.canonical_coverage_pct === undefined ? '—' : `${String(m.canonical_coverage_pct)}%`} state={kpiState} />
                <KpiCard slug="eng-gaps" icon="Search" label="Open gaps" value={num(m.open_gaps)} state={kpiState} />
                <KpiCard slug="eng-debt" icon="Activity" label="Avg debt score" value={num(m.avg_debt_score)} hint="higher = staler" state={kpiState} />
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 12, marginTop: 16 }}>
                <ChartCard slug="engagement-trend" title="Contributors trend" subtitle="recent snapshots" state={trendState}>
                    {trendState === 'ready' ? (
                        <Suspense fallback={<ChartFallback slug="engagement-trend" />}>
                            <LazyAreaChartBody data={trend} />
                        </Suspense>
                    ) : trendState === 'empty' ? (
                        <EmptyChart slug="engagement-trend" message="No snapshot history yet" />
                    ) : trendState === 'error' ? (
                        <EmptyChart slug="engagement-trend" message="Trend unavailable" />
                    ) : (
                        <ChartFallback slug="engagement-trend" />
                    )}
                </ChartCard>

                <div data-testid="admin-engagement-leaderboard" data-state={board.isError ? 'error' : board.isLoading ? 'loading' : 'ready'} style={{ border: '1px solid var(--hairline)', borderRadius: 14, padding: 16 }}>
                    <strong>🏆 Top contributors</strong>
                    {board.isError && <p role="alert" data-testid="admin-engagement-leaderboard-error">Could not load the leaderboard.</p>}
                    {board.data && board.data.leaderboard.length === 0 && <p data-testid="admin-engagement-leaderboard-empty">No contributions in the window.</p>}
                    {board.data && board.data.leaderboard.length > 0 && (
                        <ol>
                            {board.data.leaderboard.map((r) => (
                                <li key={r.user_id} data-testid={`admin-engagement-leaderboard-row-${r.user_id}`}>
                                    {r.name} <em>({r.score} pts, {r.events} events)</em>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </div>
        </section>
    );
}
