import { api } from '../../lib/api';

/**
 * v8.15/W3.2 — client for the per-user digest surfaces (W3.1 backend):
 *   GET/PUT /api/me/digest-preferences   — cadence + section toggles
 *   GET     /api/me/digest/latest        — the in-app "This week in your KB" feed
 *
 * Shapes mirror the controllers exactly (flat payloads, PUT for prefs).
 */

export interface DigestPreferences {
    frequency: string;
    sections: string[];
    available_frequencies: string[];
    available_sections: string[];
}

export interface DigestLatest {
    has_digest: boolean;
    digest: DigestFeedPayload | null;
    generated_at: string | null;
    enabled_sections: string[];
}

/** The composed digest payload (DigestPayload::toArray on the BE). */
export interface DigestFeedPayload {
    frequency?: string;
    period_start?: string;
    period_end?: string;
    narrative?: string | null;
    metrics?: Record<string, unknown>;
    new_docs?: Array<{ title: string; project_key: string; change: string }>;
    stale_docs?: Array<{ title: string; debt_score: number; age_days: number }>;
    top_gaps?: Array<{ question: string; occurrences: number }>;
    leaderboard?: Array<{ name: string; score: number }>;
    [key: string]: unknown;
}

export const digestApi = {
    async loadPreferences(): Promise<DigestPreferences> {
        const { data } = await api.get<DigestPreferences>('/api/me/digest-preferences');
        return data;
    },
    async savePreferences(patch: { frequency: string; sections: string[] | null }): Promise<DigestPreferences> {
        const { data } = await api.put<DigestPreferences>('/api/me/digest-preferences', patch);
        return data;
    },
    async latest(): Promise<DigestLatest> {
        const { data } = await api.get<DigestLatest>('/api/me/digest/latest');
        return data;
    },
};

export const DIGEST_PREFERENCES_QUERY_KEY = ['me', 'digest-preferences'] as const;
export const DIGEST_LATEST_QUERY_KEY = ['me', 'digest', 'latest'] as const;
