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
    collection_id?: number | null;
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
        (f.collection_id == null) &&
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
        f.collection_id != null,
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
    // T3.5 — composite grounding score 0..100. Populated for assistant
    // turns since the v3.0 grounding tier; null on legacy rows + on
    // refusal payloads (where confidence=0 sits at the top level too).
    confidence?: number | null;
    // T3.3/T3.4 — refusal taxonomy tag. Stays English regardless of
    // user locale (machine-readable identifier the dashboard rolls up).
    // Possible values: 'no_relevant_context' | 'llm_self_refusal' | null.
    refusal_reason?: string | null;
    // v5.0/W2 — legacy AppMessage tool-call array (pre-SDK v6 wire
    // format). `message-shape-adapters.ts::getToolCalls()` normalises
    // these into `RenderableToolCall[]` for the chat UI. Items have an
    // open shape (LLM-providers diverge on field naming), so `unknown`
    // forces every reader through the normaliser.
    tool_calls?: unknown[];
    retrieval_runner_up?: RunnerUpChunk[];
    runner_up_count?: number;
    counterfactual?: CounterfactualPanel[];
    counterfactual_count?: number;
}

export interface Message {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    metadata: MessageMetadata | null;
    rating: 'positive' | 'negative' | null;
    created_at: string;
    // T3.5 — top-level mirrors of the same metadata fields. The BE
    // populates BOTH so the FE can render the badge without digging
    // through metadata. Null on legacy rows + on user turns.
    confidence?: number | null;
    refusal_reason?: string | null;
}

export interface RunnerUpChunk {
    chunk_id: number;
    project_key: string | null;
    heading_path?: string | null;
    chunk_text?: string | null;
    vector_score?: number | null;
    reason?: string | null;
    document?: {
        id?: number | null;
        title?: string | null;
        source_path?: string | null;
        source_type?: string | null;
    } | null;
}

export interface CounterfactualPanel {
    project_key: string;
    top_chunks: RunnerUpChunk[];
}

export interface ChatCollectionOption {
    id: number;
    name: string;
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

    async listCollections(): Promise<ChatCollectionOption[]> {
        const { data } = await api.get<{ data: ChatCollectionOption[] }>('/api/kb/collections');
        return data.data;
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

    async sendChunkFeedback(
        chunkId: number,
        signal: 'should_have_cited' | 'not_relevant',
    ): Promise<{ chunk_id: number; signal: string }> {
        const { data } = await api.post<{ chunk_id: number; signal: string }>('/api/kb/feedback', {
            chunk_id: chunkId,
            signal,
        });
        return data;
    },

    /**
     * v4.5/W7 Tier 1 — fork the conversation at the given message id.
     * The new conversation contains every message up to AND INCLUDING
     * the named one. Returns the fresh conversation row so the FE can
     * navigate to it immediately. The copied message ids are also
     * returned in case the caller wants to highlight them.
     */
    async branchFromMessage(
        conversationId: number,
        messageId: number,
    ): Promise<{ conversation: Conversation; copied_message_ids: number[] }> {
        const { data } = await api.post<{
            conversation: Conversation;
            copied_message_ids: number[];
        }>(`/conversations/${conversationId}/branch-from-message/${messageId}`);
        return data;
    },

    /**
     * v4.5/W7 Tier 1 #4 — inline user-message edit: delete the message
     * being edited AND all subsequent messages from the DB so the BE
     * history window re-runs from the edit point when `sendMessage()`
     * fires next. Returns the count of deleted rows for diagnostics.
     *
     * R20: the BE is authoritative for conversation history; client-only
     * cache truncation via `chat.setMessages()` is NOT sufficient because
     * `MessageStreamController` / `MessageController` load history from
     * `$conversation->messages()`, not from the client-sent payload.
     */
    async truncateMessagesFrom(
        conversationId: number,
        messageId: number,
    ): Promise<{ deleted_count: number }> {
        const { data } = await api.delete<{ deleted_count: number }>(
            `/conversations/${conversationId}/messages-from/${messageId}`,
        );
        return data;
    },

    /**
     * v4.5/W7 Tier 2 — 3 follow-up question suggestions for the most
     * recent assistant turn. Best-effort — the BE returns
     * `{suggestions: []}` on any provider failure so the FE pill bar
     * simply doesn't render.
     */
    async suggestedFollowups(conversationId: number): Promise<string[]> {
        const { data } = await api.post<{ suggestions: string[] }>(
            `/conversations/${conversationId}/suggested-followups`,
        );
        return Array.isArray(data?.suggestions) ? data.suggestions : [];
    },
};

/**
 * v4.5/W7 Tier 1 — cost-rate lookup table. Fetched once per session
 * via TanStack Query and consumed by the token/cost meter on every
 * assistant bubble. Shape mirrors `config/ai.php::cost_rates`.
 */
export interface CostRate {
    input: number;
    output: number;
}

export type CostRateTable = Record<string, Record<string, CostRate>>;

export const chatCostApi = {
    async fetchRates(): Promise<CostRateTable> {
        const { data } = await api.get<{ rates: CostRateTable }>('/api/chat/cost-rates');
        return data?.rates ?? {};
    },
};

/**
 * Compute the per-turn USD cost from the persisted token counts +
 * the rate table. Returns `null` when the provider/model has no rate
 * (or the tokens are missing) so the FE can render `—` instead of `$0`.
 */
export function computeMessageCost(
    rates: CostRateTable,
    provider: string | undefined,
    model: string | undefined,
    promptTokens: number | undefined,
    completionTokens: number | undefined,
): number | null {
    if (!provider || !model) {
        return null;
    }
    const providerRates = rates[provider];
    if (!providerRates) {
        return null;
    }
    const rate = providerRates[model] ?? providerRates.default;
    if (!rate) {
        return null;
    }
    const inputCost = ((promptTokens ?? 0) / 1_000_000) * rate.input;
    const outputCost = ((completionTokens ?? 0) / 1_000_000) * rate.output;
    return inputCost + outputCost;
}

/**
 * T2.9-FE — saved filter presets CRUD.
 *
 * Endpoint surface (T2.9-BE shipped — see ChatFilterPresetController):
 *   GET    /api/chat-filter-presets         → list current user's presets
 *   POST   /api/chat-filter-presets         → create { name, filters }
 *   PUT    /api/chat-filter-presets/{id}    → update { name, filters }
 *   DELETE /api/chat-filter-presets/{id}    → 204
 *
 * Per-user authorization is enforced by the BE — cross-user IDs surface
 * as 404. The FE never has to reason about other users' presets.
 *
 * Wire format wraps the rows in `{ data: [...] }` to match the rest of
 * the v3.0 list endpoints; the FE unwraps consistently.
 */

export interface ChatFilterPreset {
    id: number;
    name: string;
    filters: FilterState;
    created_at?: string;
    updated_at?: string;
}

export const chatFilterPresetsApi = {
    async list(): Promise<ChatFilterPreset[]> {
        const { data } = await api.get<{ data: ChatFilterPreset[] }>('/api/chat-filter-presets');
        return data.data;
    },

    async create(name: string, filters: FilterState): Promise<ChatFilterPreset> {
        const { data } = await api.post<{ data: ChatFilterPreset }>(
            '/api/chat-filter-presets',
            { name, filters },
        );
        return data.data;
    },

    async update(id: number, name: string, filters: FilterState): Promise<ChatFilterPreset> {
        const { data } = await api.put<{ data: ChatFilterPreset }>(
            `/api/chat-filter-presets/${id}`,
            { name, filters },
        );
        return data.data;
    },

    async delete(id: number): Promise<void> {
        await api.delete(`/api/chat-filter-presets/${id}`);
    },
};
