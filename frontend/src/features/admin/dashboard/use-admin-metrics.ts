import { useQuery } from '@tanstack/react-query';
import { adminApi, type AdminMetricsQuery } from '../admin.api';

/*
 * TanStack Query hooks over the /api/admin/metrics/* endpoints.
 *
 * Keys are tuple-shaped so React Query's cache partitioning hits
 * fresh data when project/days change. Overview + series mirror the
 * server-side 30s cache: we poll once every 30s so the UI degrades
 * gracefully when the operator leaves the tab open.
 *
 * Health is polled every 15s — the probe is cheap and the dashboard
 * is the primary alerting surface.
 */

const OVERVIEW_STALE_MS = 30_000;
const HEALTH_STALE_MS = 15_000;

export function useAdminOverview(query: AdminMetricsQuery = {}) {
    return useQuery({
        queryKey: ['admin', 'metrics', 'overview', query.project ?? null, query.days ?? 7],
        queryFn: () => adminApi.overview(query),
        staleTime: OVERVIEW_STALE_MS,
        refetchInterval: OVERVIEW_STALE_MS,
    });
}

export function useAdminSeries(query: AdminMetricsQuery = {}) {
    return useQuery({
        queryKey: ['admin', 'metrics', 'series', query.project ?? null, query.days ?? 7],
        queryFn: () => adminApi.series(query),
        staleTime: OVERVIEW_STALE_MS,
        refetchInterval: OVERVIEW_STALE_MS,
    });
}

export function useAdminHealth() {
    return useQuery({
        queryKey: ['admin', 'metrics', 'health'],
        queryFn: () => adminApi.health(),
        staleTime: HEALTH_STALE_MS,
        refetchInterval: HEALTH_STALE_MS,
    });
}
