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

/**
 * v8.18/W4 — AI gamification coaching for the current user
 * (GET /api/me/coaching, self-scoped). When `available:false` the insight
 * has not been computed yet for this user.
 */
export interface MeCoachingTitle {
    key: string;
    label: string;
    icon: string;
    reason: string;
}

export interface MeCoachingNarrative {
    headline: string;
    strengths: string[];
    growth: string[];
    next_steps: string[];
    summary: string;
}

export interface MeCoachingInsight {
    scope_type: 'user';
    scope_id: string;
    period_label: string;
    metrics: Record<string, unknown>;
    narrative: MeCoachingNarrative;
    titles: MeCoachingTitle[];
    model: string | null;
    computed_at: string | null;
}

export interface MeCoachingResponse {
    available: boolean;
    insight: MeCoachingInsight | null;
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
    async coaching(): Promise<MeCoachingResponse> {
        const { data } = await api.get<MeCoachingResponse>('/api/me/coaching');
        return data;
    },
};

export const ME_DASHBOARD_QUERY_KEY = ['me', 'dashboard'] as const;
export const ME_BADGES_QUERY_KEY = ['me', 'badges'] as const;
export const ME_COACHING_QUERY_KEY = ['me', 'coaching'] as const;
