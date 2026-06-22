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

/**
 * v8.19/W4 — the agentic dimension of a column. `extract` (default) is the RAG
 * LLM path; `graph` is a deterministic governance metric (carries `metric`);
 * `verify` adds an anti-hallucination second pass. Optional so pre-v8.19
 * reviews round-trip unchanged — and so the editor PRESERVES these keys on save
 * (omitting them would silently convert a graph column back to an LLM extract).
 */
export type AgentKind = 'extract' | 'graph' | 'verify';

/** The three agentic column kinds, in editor order. */
export const AGENT_KINDS: ReadonlyArray<AgentKind> = ['extract', 'graph', 'verify'] as const;

/**
 * Mirrors `App\Services\TabularReview\GovernanceColumnResolver::METRICS` — the
 * deterministic governance signals a `graph` column can resolve. R18/R9: keep in
 * lock-step with the BE constant (the FE editor + the BE validator share it).
 */
export const GOVERNANCE_METRICS: ReadonlyArray<string> = [
    'evidence_tier',
    'frontmatter_completeness',
    'canonical_status',
    'is_canonical',
    'incoming_edges',
    'outgoing_edges',
    'graph_connectivity',
    'is_orphan',
    'supersession_status',
    'staleness_days',
] as const;

export interface ColumnConfig {
    name: string;
    prompt?: string | null;
    format: FormatType;
    enum_values?: string[];
    json_path?: string | null;
    agent?: AgentKind;
    metric?: string | null;
}

/**
 * Coerce a system workflow's `columns_config` (typed `unknown[]` on the wire)
 * into well-formed {@link ColumnConfig} rows for the create dialog. A malformed
 * template row (missing `name`, unknown `format`/`agent`/`metric`) is sanitised
 * — never trusted blind-cast — so a bad seed can't crash the editor (R14): each
 * field falls back to a safe default and rows without a usable `name` are dropped.
 */
export function normalizeTemplateColumns(raw: unknown): ColumnConfig[] {
    if (!Array.isArray(raw)) {
        return [];
    }
    const columns: ColumnConfig[] = [];
    for (const item of raw) {
        if (typeof item !== 'object' || item === null) {
            continue;
        }
        const row = item as Record<string, unknown>;
        const name = typeof row.name === 'string' ? row.name.trim() : '';
        if (name === '') {
            continue;
        }
        const format = FORMAT_TYPES.includes(row.format as FormatType) ? (row.format as FormatType) : 'text';
        const agent = AGENT_KINDS.includes(row.agent as AgentKind) ? (row.agent as AgentKind) : undefined;
        const metric =
            agent === 'graph' && typeof row.metric === 'string' && GOVERNANCE_METRICS.includes(row.metric)
                ? row.metric
                : null;
        // Preserve enum_values (e.g. enum_status columns in the seeded templates) —
        // dropping it silently loses the extraction/validation constraint.
        const enumValues = Array.isArray(row.enum_values)
            ? row.enum_values.filter((v): v is string => typeof v === 'string')
            : undefined;
        columns.push({
            name,
            prompt: typeof row.prompt === 'string' ? row.prompt : null,
            format,
            ...(enumValues && enumValues.length > 0 ? { enum_values: enumValues } : {}),
            json_path: typeof row.json_path === 'string' ? row.json_path : null,
            agent,
            metric,
        });
    }
    return columns;
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
