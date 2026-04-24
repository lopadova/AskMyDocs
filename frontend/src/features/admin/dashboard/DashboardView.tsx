import { AdminShell } from '../shell/AdminShell';
import { ActivityFeedCard } from './ActivityFeedCard';
import { ChatVolumeCard } from './ChatVolumeCard';
import type { ChartState } from './ChartCard';
import { HealthStrip } from './HealthStrip';
import { KpiStrip } from './KpiStrip';
import { RatingDonutCard } from './RatingDonutCard';
import { TokenBurnCard } from './TokenBurnCard';
import { TopProjectsCard } from './TopProjectsCard';
import { useAdminHealth, useAdminOverview, useAdminSeries } from './use-admin-metrics';

/*
 * Admin Dashboard root. Mounted at `/app/admin` after the RequireRole
 * guard accepts the caller. Everything is driven by three TanStack
 * Query hooks — overview (KPIs), series (charts + feed), health —
 * each self-polling on the intervals documented in use-admin-metrics.
 *
 * The window defaults to 7 days; the dropdown is intentionally left
 * out of Phase F1 (PR #10 wires the Insights date picker).
 */

const DAYS = 7;

function stateFromQuery(
    isLoading: boolean,
    isFetching: boolean,
    hasError: boolean,
    hasData: boolean,
): ChartState {
    if (isLoading || (isFetching && !hasData)) {
        return 'loading';
    }
    if (hasError) {
        return 'error';
    }
    return hasData ? 'ready' : 'empty';
}

export function DashboardView() {
    const overview = useAdminOverview({ days: DAYS });
    const series = useAdminSeries({ days: DAYS });
    const health = useAdminHealth();

    const kpiState = stateFromQuery(
        overview.isLoading,
        overview.isFetching,
        overview.isError,
        overview.data !== undefined,
    );
    const seriesState = stateFromQuery(
        series.isLoading,
        series.isFetching,
        series.isError,
        series.data !== undefined,
    );
    const healthState = health.isError ? 'error' : health.isLoading ? 'loading' : 'ready';

    return (
        <AdminShell section="dashboard">
            <div
                data-testid="admin-dashboard"
                data-state={kpiState === 'ready' && seriesState === 'ready' ? 'ready' : kpiState}
                style={{ display: 'flex', flexDirection: 'column', gap: 0 }}
            >
                <div style={{ marginBottom: 12 }}>
                    <h1
                        style={{
                            fontSize: 20,
                            fontWeight: 600,
                            margin: '0 0 2px',
                            letterSpacing: '-0.02em',
                            color: 'var(--fg-0)',
                        }}
                    >
                        Dashboard
                    </h1>
                    <p
                        style={{
                            fontSize: 12.5,
                            color: 'var(--fg-3)',
                            margin: 0,
                        }}
                    >
                        Rolling {DAYS}-day view · refresh every 30 seconds
                    </p>
                </div>
                <HealthStrip health={health.data ?? null} state={healthState} />
                <KpiStrip
                    overview={overview.data?.overview ?? null}
                    state={kpiState === 'ready' && overview.data === undefined ? 'empty' : kpiState}
                />

                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))',
                        gap: 12,
                    }}
                >
                    <ChatVolumeCard
                        data={series.data?.chat_volume ?? []}
                        state={seriesState}
                        days={DAYS}
                    />
                    <TokenBurnCard
                        data={series.data?.token_burn ?? []}
                        state={seriesState}
                        days={DAYS}
                    />
                    <RatingDonutCard
                        distribution={series.data?.rating_distribution ?? null}
                        state={seriesState}
                        days={DAYS}
                    />
                    <TopProjectsCard rows={series.data?.top_projects ?? []} state={seriesState} />
                </div>

                <div style={{ marginTop: 12 }}>
                    <ActivityFeedCard rows={series.data?.activity_feed ?? []} state={seriesState} />
                </div>
            </div>
        </AdminShell>
    );
}
