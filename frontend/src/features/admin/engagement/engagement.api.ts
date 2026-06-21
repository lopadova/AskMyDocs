import { api } from '../../../lib/api';

/**
 * v8.15/W4.2 — admin engagement analytics client (W1/W4.1 backend):
 *   GET /api/admin/engagement/summary | /leaderboard | /series
 */
export interface EngagementSummary {
    source: string;
    snapshot_date: string | null;
    computed_at: string | null;
    metrics: Record<string, unknown>;
}

export interface EngagementLeaderboardRow {
    user_id: number;
    name: string;
    score: number;
    events: number;
}

export interface EngagementTrendPoint {
    date: string;
    contributors: number;
    new_docs: number;
    answers: number;
    avg_debt_score: number | null;
}

/**
 * v8.18/W4 — AI gamification insights (project/tenant health narrative):
 *   GET  /api/admin/engagement/insights?scope=project|tenant&id=<projectKey>
 *   POST /api/admin/engagement/insights/regenerate (super-admin only)
 */
export type GamificationScope = 'project' | 'tenant';

export interface GamificationNarrative {
    headline: string;
    summary: string;
    actions: string[];
    advice?: string[];
}

export interface GamificationTitle {
    key: string;
    label: string;
    icon: string;
    reason: string;
}

export interface GamificationInsight {
    scope_type: string;
    scope_id: string;
    period_label: string;
    metrics: Record<string, unknown>;
    narrative: GamificationNarrative;
    titles: GamificationTitle[];
    model: string | null;
    computed_at: string | null;
}

export interface GamificationInsightsResponse {
    available: boolean;
    scope: string;
    scope_id: string;
    insight: GamificationInsight | null;
}

export interface RegenerateInsightsResult {
    period: string;
    users: number;
    projects: number;
    tenant: number;
}

export interface RegenerateInsightsResponse {
    regenerated: boolean;
    result: RegenerateInsightsResult;
}

export const engagementApi = {
    async summary(): Promise<EngagementSummary> {
        const { data } = await api.get<EngagementSummary>('/api/admin/engagement/summary');
        return data;
    },
    async leaderboard(days = 30, limit = 10): Promise<{ leaderboard: EngagementLeaderboardRow[] }> {
        const { data } = await api.get<{ leaderboard: EngagementLeaderboardRow[] }>('/api/admin/engagement/leaderboard', { params: { days, limit } });
        return data;
    },
    async series(points = 8): Promise<{ series: EngagementTrendPoint[] }> {
        const { data } = await api.get<{ series: EngagementTrendPoint[] }>('/api/admin/engagement/series', { params: { points } });
        return data;
    },
    async insights(scope: GamificationScope = 'tenant', id?: string): Promise<GamificationInsightsResponse> {
        const params: Record<string, string> = { scope };
        if (id) {
            params.id = id;
        }
        const { data } = await api.get<GamificationInsightsResponse>('/api/admin/engagement/insights', { params });
        return data;
    },
    async regenerateInsights(): Promise<RegenerateInsightsResponse> {
        const { data } = await api.post<RegenerateInsightsResponse>('/api/admin/engagement/insights/regenerate');
        return data;
    },
};

export const ENGAGEMENT_SUMMARY_QUERY_KEY = ['admin', 'engagement', 'summary'] as const;
export const ENGAGEMENT_LEADERBOARD_QUERY_KEY = ['admin', 'engagement', 'leaderboard'] as const;
export const ENGAGEMENT_SERIES_QUERY_KEY = ['admin', 'engagement', 'series'] as const;
export const GAMIFICATION_INSIGHTS_QUERY_KEY = ['admin', 'engagement', 'insights'] as const;
