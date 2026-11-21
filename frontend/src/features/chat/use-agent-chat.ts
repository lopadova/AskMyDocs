import { useCallback, useEffect, useMemo, type SetStateAction } from 'react';
import { useStore } from 'zustand';
import { useTeamStore } from '../../lib/team-store';
import { getAgentChatSession, type AgentChatState, type AgentMessageOptions } from './agent-chat-session';
import type { AgentTurnStarted, FilterState, LiveSourceSelection, Message } from './chat.api';

export type { AgentConfirmation } from './agent-chat-session';

export interface UseAgentChatOptions {
    conversationId: number | null;
    filters: FilterState;
    liveSources?: LiveSourceSelection;
    /** Investigation depth (1-5, default 3). */
    depth?: number;
    initialMessages?: Message[];
    onFinish?: () => void;
    onError?: (error: Error) => void;
}

export interface UseAgentChatResult extends Omit<AgentChatState, 'unreadAt'> {
    sendMessage: (message: { text: string }, options?: AgentMessageOptions) => Promise<void>;
    stop: (runId?: string) => void;
    regenerate: () => void;
    continueRun: (runId?: string) => Promise<void>;
    adoptExternalRun: (run: AgentTurnStarted) => Promise<void>;
    setMessages: (next: SetStateAction<Message[]>) => void;
}

export function useAgentChat(options: UseAgentChatOptions): UseAgentChatResult {
    const { conversationId, initialMessages } = options;
    const userId = useTeamStore((state) => state.userId);
    const tenantId = useTeamStore((state) => state.currentTeam);
    const session = useMemo(() => getAgentChatSession(conversationId, userId, tenantId), [conversationId, userId, tenantId]);
    const state = useStore(session.store);

    // Opening reads the answer; leaving detaches the view, never the run.
    useEffect(() => session.attach(), [session]);
    useEffect(() => {
        if (initialMessages !== undefined) session.hydrate(initialMessages);
    }, [session, initialMessages]);

    // Capture callbacks at dispatch, bound to the conversation owning the
    // request. Foreground navigation must never redirect its completion.
    const sendMessage = useCallback((message: { text: string }, messageOptions?: AgentMessageOptions) =>
        session.sendMessage(message, options, messageOptions), [session, options]);
    const adoptExternalRun = useCallback((run: AgentTurnStarted) => session.adoptExternalRun(run, options), [session, options]);
    const continueRun = useCallback((runId?: string) => session.continueRun(options, runId), [session, options]);
    const regenerate = useCallback(() => {
        const { messages, concurrentSession } = session.store.getState();
        const newestFirst = [...messages].reverse();
        const lastReply = concurrentSession ? newestFirst.find((message) => message.role === 'assistant' && !message.metadata?.session_interim) : null;
        const replyTo = lastReply?.metadata?.reply_to_user_message_id;
        const question = newestFirst.find((message) => message.role === 'user' && (replyTo === undefined || message.id === replyTo));
        if (question) void sendMessage({ text: question.content }).catch(() => undefined);
    }, [session, sendMessage]);

    return { ...state, sendMessage, adoptExternalRun, continueRun, regenerate, stop: session.stop, setMessages: session.setMessages };
}
