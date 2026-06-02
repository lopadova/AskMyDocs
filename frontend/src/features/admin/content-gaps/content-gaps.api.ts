import { api } from '../../../lib/api';

/**
 * v8.8/W4 — content-gap analytics: questions the KB could not answer.
 */

export interface ContentGap {
    id: number;
    project_key: string;
    query_text: string;
    reason: string;
    occurrences: number;
    last_seen_at: string | null;
    resolved_at: string | null;
}

export interface ContentGapsResponse {
    data: ContentGap[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        /** R18 — reason codes present in this tenant's gap table (DB-derived). */
        available_reasons: string[];
    };
}

/**
 * Human-readable labels for known machine reason codes. BE stays English;
 * this is a display-only utility. Falls back to the raw code for unknown
 * values so new reason codes render without a deploy.
 */
export const REASON_LABELS: Record<string, string> = {
    no_relevant_context: 'No relevant context',
    llm_self_refusal: 'Model self-refusal',
};

export function reasonLabel(reason: string): string {
    return REASON_LABELS[reason] ?? reason;
}

export async function getContentGaps(params: { reason?: string; includeResolved?: boolean } = {}): Promise<ContentGapsResponse> {
    const qs = new URLSearchParams();
    if (params.reason) qs.set('reason', params.reason);
    if (params.includeResolved) qs.set('include_resolved', '1');
    const suffix = qs.toString() ? `?${qs.toString()}` : '';
    const { data } = await api.get<ContentGapsResponse>(`/api/admin/kb/content-gaps${suffix}`);
    return data;
}

export async function resolveContentGap(id: number): Promise<{ ok: boolean; id: number; resolved_at: string }> {
    const { data } = await api.patch(`/api/admin/kb/content-gaps/${id}/resolve`);
    return data;
}
