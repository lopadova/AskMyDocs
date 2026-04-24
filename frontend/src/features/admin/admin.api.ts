import { api } from '../../lib/api';

/*
 * Admin dashboard HTTP layer. Mirrors chat.api.ts: thin typed axios
 * wrappers with zero business logic. Consumers live in
 * dashboard/use-admin-metrics.ts + the route-guard reading the auth
 * store. See app/Http/Controllers/Api/Admin/DashboardMetricsController
 * for the source of truth — keep this file in lockstep (R9).
 */

export interface AdminKpiOverview {
    total_docs: number;
    total_chunks: number;
    total_chats: number;
    avg_latency_ms: number;
    failed_jobs: number;
    pending_jobs: number;
    cache_hit_rate: number;
    canonical_coverage_pct: number;
    storage_used_mb: number;
}

export interface AdminOverviewResponse {
    project: string | null;
    days: number;
    overview: AdminKpiOverview;
}

export interface AdminChatVolumeRow {
    date: string;
    count: number;
}

export interface AdminTokenBurnRow {
    provider: string;
    prompt_tokens: number;
    completion_tokens: number;
    total_tokens: number;
}

export interface AdminRatingDistribution {
    positive: number;
    negative: number;
    unrated: number;
    total: number;
}

export interface AdminTopProjectRow {
    project_key: string;
    count: number;
}

export interface AdminActivityRow {
    source: 'chat' | 'audit';
    id: number;
    actor: string;
    action: string;
    target: string;
    project: string;
    created_at: string;
}

export interface AdminSeriesResponse {
    project: string | null;
    days: number;
    chat_volume: AdminChatVolumeRow[];
    token_burn: AdminTokenBurnRow[];
    rating_distribution: AdminRatingDistribution;
    top_projects: AdminTopProjectRow[];
    activity_feed: AdminActivityRow[];
}

export type HealthStatus = 'ok' | 'degraded' | 'down';

export interface AdminHealth {
    db_ok: HealthStatus;
    pgvector_ok: HealthStatus;
    queue_ok: HealthStatus;
    kb_disk_ok: HealthStatus;
    embedding_provider_ok: HealthStatus;
    chat_provider_ok: HealthStatus;
    checked_at: string;
}

export interface AdminMetricsQuery {
    project?: string | null;
    days?: number;
}

function buildParams(q: AdminMetricsQuery): Record<string, string> {
    const params: Record<string, string> = {};
    if (q.project) {
        params.project = q.project;
    }
    if (typeof q.days === 'number') {
        params.days = String(q.days);
    }
    return params;
}

export const adminApi = {
    async overview(q: AdminMetricsQuery = {}): Promise<AdminOverviewResponse> {
        const { data } = await api.get<AdminOverviewResponse>('/api/admin/metrics/overview', {
            params: buildParams(q),
        });
        return data;
    },

    async series(q: AdminMetricsQuery = {}): Promise<AdminSeriesResponse> {
        const { data } = await api.get<AdminSeriesResponse>('/api/admin/metrics/series', {
            params: buildParams(q),
        });
        return data;
    },

    async health(): Promise<AdminHealth> {
        const { data } = await api.get<AdminHealth>('/api/admin/metrics/health');
        return data;
    },
};
