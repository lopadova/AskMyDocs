import { api } from '../../lib/api';

/*
 * Chat HTTP layer. Thin typed wrappers over the existing Laravel
 * endpoints (see ConversationController / MessageController /
 * FeedbackController). No business logic here — that lives in the
 * TanStack Query hooks below + useChatMutation.
 */

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

    async sendMessage(conversationId: number, content: string): Promise<Message> {
        const { data } = await api.post<Message>(`/conversations/${conversationId}/messages`, {
            content,
        });
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
