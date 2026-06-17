import { api } from '../../lib/api';

/**
 * v8.15/W4.2 — client for the per-user "your KB" dashboard (GET /api/me/dashboard).
 */
export interface MeDashboard {
    window_days: number;
    contributions: { score: number; events: number; by_event: Record<string, number>; citations: number };
    rank: number | null;
    authored_docs: number;
    questions_asked: number;
    active_days: number;
    docs_needing_review: Array<{ title: string; slug: string | null; debt_score: number }>;
}

export interface MeDashboardResponse {
    window_days: number;
    dashboard: MeDashboard;
}

export interface MeBadge {
    key: string;
    label: string;
    icon: string;
    metric: string;
    threshold: number;
    progress: number;
    earned: boolean;
    awarded_at: string | null;
}

export interface MeBadgesResponse {
    enabled: boolean;
    badges: MeBadge[];
}

export const meDashboardApi = {
    async load(days = 30): Promise<MeDashboardResponse> {
        const { data } = await api.get<MeDashboardResponse>('/api/me/dashboard', { params: { days } });
        return data;
    },
    async badges(): Promise<MeBadgesResponse> {
        const { data } = await api.get<MeBadgesResponse>('/api/me/badges');
        return data;
    },
};

export const ME_DASHBOARD_QUERY_KEY = ['me', 'dashboard'] as const;
export const ME_BADGES_QUERY_KEY = ['me', 'badges'] as const;
