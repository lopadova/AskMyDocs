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
    stop: () => void;
    regenerate: () => void;
    continueRun: () => Promise<void>;
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
    const continueRun = useCallback(() => session.continueRun(options), [session, options]);
    const regenerate = useCallback(() => {
        const last = [...session.store.getState().messages].reverse().find((message) => message.role === 'user');
        if (last) void sendMessage({ text: last.content }).catch(() => undefined);
    }, [session, sendMessage]);

    return { ...state, sendMessage, adoptExternalRun, continueRun, regenerate, stop: session.stop, setMessages: session.setMessages };
}
