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
};

export const ENGAGEMENT_SUMMARY_QUERY_KEY = ['admin', 'engagement', 'summary'] as const;
export const ENGAGEMENT_LEADERBOARD_QUERY_KEY = ['admin', 'engagement', 'leaderboard'] as const;
export const ENGAGEMENT_SERIES_QUERY_KEY = ['admin', 'engagement', 'series'] as const;
