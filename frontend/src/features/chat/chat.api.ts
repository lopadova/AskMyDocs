import { api } from '../../lib/api';

/*
 * Chat HTTP layer. Thin typed wrappers over the existing Laravel
 * endpoints (see ConversationController / MessageController /
 * FeedbackController). No business logic here — that lives in the
 * TanStack Query hooks below + useChatMutation.
 */

/**
 * T2.7 / T2.1 — multi-dimension retrieval filters that the chat
 * composer threads into both `/api/kb/chat` (stateless) and
 * `/conversations/{id}/messages` (conversation flow). Mirrors
 * `App\Services\Kb\Retrieval\RetrievalFilters` on the BE byte-for-byte
 * (R20 — route contracts match FE payload shape). All keys are
 * optional and typed as `string[]` / `number[]` so an empty array
 * means "no constraint on this dimension"; an undefined / missing
 * key means the same thing — the BE treats both as "no filter".
 *
 * The BE applies these filters across:
 *   projectKeys     → chunk-level project_key WHERE-IN
 *   tagSlugs        → kb_tags pivot whereExists subquery
 *   sourceTypes     → document.source_type WHERE-IN
 *   canonicalTypes  → document.canonical_type WHERE-IN (pre-validated enum)
 *   docIds          → document.id WHERE-IN (powers @mention pinning)
 *   folderGlobs     → fnmatch-with-** post-fetch filter via KbPath
 *   dateFrom/dateTo → document.indexed_at BETWEEN
 *   languages       → document.language WHERE-IN
 *
 * Field naming uses snake_case to match the JSON wire format —
 * backend's RetrievalFilters DTO uses `project_keys`, `tag_slugs`,
 * etc.; the FE state type stays consistent so the mapping is
 * `JSON.stringify(filterState)` with no transformation step.
 */
export interface FilterState {
    project_keys?: string[];
    tag_slugs?: string[];
    source_types?: string[];
    canonical_types?: string[];
    connector_types?: string[];
    doc_ids?: number[];
    folder_globs?: string[];
    date_from?: string | null;
    date_to?: string | null;
    languages?: string[];
}

/**
 * Returns true when EVERY filter dimension is empty / undefined.
 * Used by the composer to skip the `filters` payload key entirely
 * (BE accepts both omitted and empty equivalently per the
 * `effectiveProjectKey()` legacy fallback in KbChatRequest).
 */
export function isFilterStateEmpty(f: FilterState): boolean {
    return (
        (f.project_keys?.length ?? 0) === 0 &&
        (f.tag_slugs?.length ?? 0) === 0 &&
        (f.source_types?.length ?? 0) === 0 &&
        (f.canonical_types?.length ?? 0) === 0 &&
        (f.connector_types?.length ?? 0) === 0 &&
        (f.doc_ids?.length ?? 0) === 0 &&
        (f.folder_globs?.length ?? 0) === 0 &&
        (f.languages?.length ?? 0) === 0 &&
        (f.date_from == null) &&
        (f.date_to == null)
    );
}

/**
 * Counts the dimensions the user has actively constrained. Mirrors
 * the BE's `meta.filters_selected` semantic: a bool-coerced sum, not
 * a tally of values within a dimension. Used by the FilterBar to
 * render "5 filters selected" without an extra round-trip.
 */
export function countSelectedFilters(f: FilterState): number {
    return [
        (f.project_keys?.length ?? 0) > 0,
        (f.tag_slugs?.length ?? 0) > 0,
        (f.source_types?.length ?? 0) > 0,
        (f.canonical_types?.length ?? 0) > 0,
        (f.connector_types?.length ?? 0) > 0,
        (f.doc_ids?.length ?? 0) > 0,
        (f.folder_globs?.length ?? 0) > 0,
        (f.languages?.length ?? 0) > 0,
        f.date_from != null,
        f.date_to != null,
    ].filter(Boolean).length;
}

export interface Conversation {
    id: number;
    title: string | null;
    project_key: string | null;
    created_at: string;
    updated_at: string;
}

export interface MessageCitation {
    document_id: number | null;
    title: string;
    source_path: string | null;
    source_type?: string | null;
    headings?: string[];
    chunks_used?: number;
    origin?: 'primary' | 'related' | 'rejected';
}

export interface MessageMetadata {
    provider?: string;
    model?: string;
    prompt_tokens?: number;
    completion_tokens?: number;
    total_tokens?: number;
    chunks_count?: number;
    latency_ms?: number;
    citations?: MessageCitation[];
    few_shot_count?: number;
    // Populated when the AI provider returns a reasoning trace.
    // MessageBubble renders these inside <ThinkingTrace>.
    reasoning_steps?: string[];
}

export interface Message {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    metadata: MessageMetadata | null;
    rating: 'positive' | 'negative' | null;
    created_at: string;
}

export const chatApi = {
    async listConversations(): Promise<Conversation[]> {
        const { data } = await api.get<Conversation[]>('/conversations');
        return data;
    },

    async createConversation(projectKey: string | null): Promise<Conversation> {
        const { data } = await api.post<Conversation>('/conversations', {
            project_key: projectKey,
        });
        return data;
    },

    async renameConversation(id: number, title: string): Promise<Conversation> {
        const { data } = await api.patch<Conversation>(`/conversations/${id}`, { title });
        return data;
    },

    async deleteConversation(id: number): Promise<void> {
        await api.delete(`/conversations/${id}`);
    },

    async generateTitle(id: number): Promise<{ title: string }> {
        const { data } = await api.post<{ title: string }>(`/conversations/${id}/generate-title`);
        return data;
    },

    async listMessages(conversationId: number): Promise<Message[]> {
        const { data } = await api.get<Message[]>(`/conversations/${conversationId}/messages`);
        return data;
    },

    async sendMessage(
        conversationId: number,
        content: string,
        filters?: FilterState,
    ): Promise<Message> {
        // T2.7 — `filters` is omitted from the payload when empty so
        // the BE's effectiveProjectKey() legacy fallback works
        // unchanged for callers that don't surface the filter UI yet.
        const payload = filters && !isFilterStateEmpty(filters)
            ? { content, filters }
            : { content };
        const { data } = await api.post<Message>(`/conversations/${conversationId}/messages`, payload);
        return data;
    },

    async rateMessage(
        conversationId: number,
        messageId: number,
        rating: 'positive' | 'negative',
    ): Promise<{ rating: 'positive' | 'negative' | null }> {
        const { data } = await api.post<{ rating: 'positive' | 'negative' | null }>(
            `/conversations/${conversationId}/messages/${messageId}/feedback`,
            { rating },
        );
        return data;
    },
};
