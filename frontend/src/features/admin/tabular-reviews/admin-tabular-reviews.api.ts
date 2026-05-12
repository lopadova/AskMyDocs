import { api } from '../../../lib/api';

/**
 * v4.7/W3 — Admin Tabular Reviews API client.
 *
 * Wraps `/api/admin/tabular-reviews/*` with typed methods. Follows the
 * `{ data: ... }` envelope used across the rest of the admin surface
 * (T2.10 tags, Phase G1 KB, Phase H1 logs, etc.).
 *
 * `generate-stream` returns an `EventSource` rather than a Promise so
 * the caller can subscribe to per-cell SSE events.
 */

export type FormatType =
    | 'text'
    | 'enum_status'
    | 'enum'
    | 'number'
    | 'date'
    | 'person'
    | 'entity'
    | 'list'
    | 'json_path'
    | 'currency'
    | 'percent'
    | 'duration'
    | 'flag'
    | 'boolean'
    | 'choice'
    | 'free_text';

export interface ColumnConfig {
    name: string;
    prompt?: string | null;
    format: FormatType;
    enum_values?: string[];
    json_path?: string | null;
}

export interface TabularReview {
    id: number;
    project_key: string;
    title: string;
    columns_config: ColumnConfig[];
    practice?: string | null;
    user_id?: number;
    created_at?: string;
    updated_at?: string;
}

export interface TabularCell {
    id: number;
    review_id: number;
    document_id: number;
    column_index: number;
    content: { summary?: string | null; reasoning?: string | null; citations?: unknown[] } | null;
    status: string;
    flag: string;
}

export interface CreateReviewPayload {
    project_key: string;
    title: string;
    columns_config: ColumnConfig[];
    practice?: string | null;
}

export interface ListMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export const adminTabularReviewsApi = {
    async list(projectKey?: string): Promise<{ data: TabularReview[]; meta: ListMeta }> {
        const qs = projectKey ? `?project_key=${encodeURIComponent(projectKey)}` : '';
        const { data } = await api.get<{ data: TabularReview[]; meta: ListMeta }>(
            `/api/admin/tabular-reviews${qs}`
        );
        return data;
    },

    async show(id: number): Promise<{ data: TabularReview; cells: TabularCell[]; cells_meta: any }> {
        const { data } = await api.get<{ data: TabularReview; cells: TabularCell[]; cells_meta: any }>(
            `/api/admin/tabular-reviews/${id}`
        );
        return data;
    },

    async create(payload: CreateReviewPayload): Promise<TabularReview> {
        const { data } = await api.post<{ data: TabularReview }>(`/api/admin/tabular-reviews`, payload);
        return data.data;
    },

    async destroy(id: number): Promise<void> {
        await api.delete(`/api/admin/tabular-reviews/${id}`);
    },

    async generate(id: number, maxDocuments?: number): Promise<any> {
        const { data } = await api.post(`/api/admin/tabular-reviews/${id}/generate`, {
            max_documents: maxDocuments,
        });
        return data;
    },

    async clearCells(id: number): Promise<void> {
        await api.post(`/api/admin/tabular-reviews/${id}/clear-cells`);
    },
};
