import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../../lib/api';

/*
 * Phase I — admin AI insights HTTP + TanStack Query hooks.
 *
 * Mirrors routes/api.php `/api/admin/insights/*` exactly. Keep this
 * module in lockstep with AdminInsightsController (R9 — docs match
 * code).
 *
 * The SPA never triggers LLM calls on the read path: /latest + /{date}
 * read the pre-computed snapshot row; /compute is super-admin-only
 * recomputation. Per-doc suggestions (/document/{id}/ai-suggestions)
 * DO call the LLM on demand, so the hook has retry=false + a modest
 * staleTime to avoid accidental double-fetches.
 */

// ---------------------------------------------------------------------------
// Shapes
// ---------------------------------------------------------------------------

export interface PromotionSuggestion {
    document_id: number;
    project_key: string;
    slug: string | null;
    title: string | null;
    reason: string;
    score: number;
}

export interface OrphanDoc {
    document_id: number;
    project_key: string;
    slug: string | null;
    title: string | null;
    last_used_at: string | null;
    chunks_count: number;
}

export interface SuggestedTagsRow {
    document_id: number;
    project_key: string;
    slug: string | null;
    title: string | null;
    tags_proposed: string[];
}

export interface CoverageGap {
    topic: string;
    zero_citation_count: number;
    low_confidence_count: number;
    sample_questions: string[];
}

export interface StaleDoc {
    document_id: number;
    project_key: string;
    slug: string | null;
    title: string | null;
    indexed_at: string | null;
    negative_rating_ratio: number;
}

export interface QualityReport {
    chunk_length_distribution: {
        under_100: number;
        h100_500: number;
        h500_1000: number;
        h1000_2000: number;
        over_2000: number;
    };
    outlier_short: number;
    outlier_long: number;
    missing_frontmatter: number;
    total_docs: number;
    total_chunks: number;
}

export interface InsightsSnapshot {
    id: number;
    snapshot_date: string | null;
    suggest_promotions: PromotionSuggestion[] | null;
    orphan_docs: OrphanDoc[] | null;
    suggested_tags: SuggestedTagsRow[] | null;
    coverage_gaps: CoverageGap[] | null;
    stale_docs: StaleDoc[] | null;
    quality_report: QualityReport | null;
    computed_at: string | null;
    computed_duration_ms: number | null;
}

export interface InsightsResponse {
    data: InsightsSnapshot;
}

// ---------------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------------

export const INSIGHTS_LATEST_KEY = ['admin', 'insights', 'latest'] as const;

export function useInsightsLatest() {
    return useQuery<InsightsResponse>({
        queryKey: INSIGHTS_LATEST_KEY,
        queryFn: async () => {
            const { data } = await api.get<InsightsResponse>('/api/admin/insights/latest');
            return data;
        },
        staleTime: 60_000,
        retry: false,
    });
}

export const INSIGHTS_BY_DATE_KEY = ['admin', 'insights', 'by-date'] as const;

export function useInsightsByDate(date: string | null) {
    return useQuery<InsightsResponse>({
        queryKey: [...INSIGHTS_BY_DATE_KEY, date],
        queryFn: async () => {
            const { data } = await api.get<InsightsResponse>(`/api/admin/insights/${date}`);
            return data;
        },
        enabled: !!date,
        staleTime: 60_000,
        retry: false,
    });
}

// ---------------------------------------------------------------------------
// Mutation — compute (super-admin only at the API layer)
// ---------------------------------------------------------------------------

export interface ComputeResponse {
    message: string;
    audit_id: number;
}

export function useComputeInsights() {
    const qc = useQueryClient();
    return useMutation<ComputeResponse, unknown>({
        mutationFn: async () => {
            const { data } = await api.post<ComputeResponse>('/api/admin/insights/compute');
            return data;
        },
        onSuccess: () => {
            // Invalidate the snapshot queries so the UI refreshes.
            qc.invalidateQueries({ queryKey: INSIGHTS_LATEST_KEY });
            qc.invalidateQueries({ queryKey: INSIGHTS_BY_DATE_KEY });
        },
    });
}

// ---------------------------------------------------------------------------
// Per-document AI suggestions (one LLM call on the read path — the
// single endpoint that does)
// ---------------------------------------------------------------------------

export interface DocumentSuggestionsResponse {
    data: {
        document_id: number;
        slug: string | null;
        tags_proposed: string[];
    };
}

export const DOCUMENT_SUGGESTIONS_KEY = ['admin', 'insights', 'document-suggestions'] as const;

export function useDocumentAiSuggestions(documentId: number | null) {
    return useQuery<DocumentSuggestionsResponse>({
        queryKey: [...DOCUMENT_SUGGESTIONS_KEY, documentId],
        queryFn: async () => {
            const { data } = await api.get<DocumentSuggestionsResponse>(
                `/api/admin/insights/document/${documentId}/ai-suggestions`,
            );
            return data;
        },
        enabled: typeof documentId === 'number' && documentId > 0,
        staleTime: 5 * 60_000,
        retry: false,
    });
}
