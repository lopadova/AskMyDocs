import { api } from '../../../lib/api';

/**
 * v4.7/W3 — Admin Tabular Reviews API client.
 *
 * Wraps `/api/admin/tabular-reviews/*` with typed methods. Follows the
 * `{ data: ... }` envelope used across the rest of the admin surface
 * (T2.10 tags, Phase G1 KB, Phase H1 logs, etc.).
 *
 * The synchronous `/generate` endpoint is exposed here as a typed
 * Promise. The SSE streaming variant (`/generate-stream`) is consumed
 * by a fetch-based SSE client constructed by the show-page hook in
 * v4.7.x — native `EventSource` is NOT used because the streaming
 * route is POST (EventSource is GET-only) and we need to send a
 * JSON body + Sanctum CSRF/auth cookies. The hook owns the
 * subscription lifecycle (open / event-by-event handler / close)
 * and avoids leaking a long-lived readable stream through the axios
 * layer.
 */

/**
 * Mirrors `App\Support\TabularReview\FormatType` (17 cases as of
 * v4.7 GA). Adding a new format requires touching both:
 *   - this union, AND
 *   - `app/Support/TabularReview/FormatType.php` (single source of
 *     truth on the BE).
 * Copilot iter 4 caught a divergence here — the FE union had Mike-
 * style literals (`free_text` / `percent` / `duration` / `boolean` /
 * `choice` / `flag` / `entity` / `list`) that don't exist on the
 * BE enum. R18 / R9: align FE to the real domain.
 */
export type FormatType =
    | 'text'
    | 'bulleted_list'
    | 'number'
    | 'percentage'
    | 'monetary_amount'
    | 'currency'
    | 'yes_no'
    | 'date'
    | 'tag'
    | 'enum'
    | 'enum_status'
    | 'rating'
    | 'url'
    | 'person'
    | 'tags_multi'
    | 'relation'
    | 'json_path';

/**
 * Ordered list of every FormatType the BE accepts. Derived from
 * `App\Support\TabularReview\FormatType` (`values()` static).
 *
 * Use this constant — NOT a literal subset — when rendering a
 * format-picker so the FE never drifts from the BE enum (R18).
 */
export const FORMAT_TYPES: ReadonlyArray<FormatType> = [
    'text',
    'bulleted_list',
    'number',
    'percentage',
    'monetary_amount',
    'currency',
    'yes_no',
    'date',
    'tag',
    'enum',
    'enum_status',
    'rating',
    'url',
    'person',
    'tags_multi',
    'relation',
    'json_path',
] as const;

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
        // Copilot iter 6 — list endpoint is paginated (default per_page=25,
        // max 100). v4.7 GA bumps to per_page=100 so the typical admin
        // case (a few dozen reviews per tenant) renders without pagination
        // controls. Full Prev/Next wiring lands in v4.7.x polish.
        const params = new URLSearchParams();
        params.set('per_page', '100');
        if (projectKey) params.set('project_key', projectKey);
        const { data } = await api.get<{ data: TabularReview[]; meta: ListMeta }>(
            `/api/admin/tabular-reviews?${params.toString()}`
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
