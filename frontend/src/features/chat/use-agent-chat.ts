import { useCallback, useEffect, useRef, useState, type SetStateAction } from 'react';
import type { UseChatHelpers } from '@ai-sdk/react';
import type { UIMessage } from 'ai';
import {
    consumeAgentRun,
    type AgentRunEvent,
    type AgentRunStreamResult,
} from '../../lib/agent-run-events';
import { useTeamStore } from '../../lib/team-store';
import {
    chatApi,
    isFilterStateEmpty,
    type AgentTurnStarted,
    type FilterState,
    type Message,
} from './chat.api';

type ChatStatus = UseChatHelpers<UIMessage>['status'];

export interface UseAgentChatOptions {
    conversationId: number | null;
    filters: FilterState;
    initialMessages?: Message[];
    onFinish?: () => void;
    onError?: (error: Error) => void;
}

export interface AgentConfirmation {
    physicalExtension: number;
    logicalExtension: number;
}

export interface UseAgentChatResult {
    messages: Message[];
    status: ChatStatus;
    error: Error | null;
    events: AgentRunEvent[];
    activeRun: AgentTurnStarted | null;
    confirmation: AgentConfirmation | null;
    sendMessage: (message: { text: string }) => Promise<void>;
    stop: () => void;
    regenerate: () => void;
    continueRun: () => Promise<void>;
    setMessages: (next: SetStateAction<Message[]>) => void;
}

export function useAgentChat(options: UseAgentChatOptions): UseAgentChatResult {
    const { conversationId, filters, initialMessages, onFinish, onError } = options;
    const [messages, setMessages] = useState<Message[]>(initialMessages ?? []);
    const [status, setStatus] = useState<ChatStatus>('ready');
    const [error, setError] = useState<Error | null>(null);
    const [events, setEvents] = useState<AgentRunEvent[]>([]);
    const [activeRun, setActiveRun] = useState<AgentTurnStarted | null>(null);
    const [confirmation, setConfirmation] = useState<AgentConfirmation | null>(null);
    const abortRef = useRef<AbortController | null>(null);
    const runRef = useRef<AgentTurnStarted | null>(null);
    const turnInFlightRef = useRef(false);
    const lastSequenceRef = useRef(0);
    const generationRef = useRef(0);
    const filtersRef = useRef(filters);
    const initialMessagesRef = useRef(initialMessages);
    const callbacksRef = useRef({ onFinish, onError });

    filtersRef.current = filters;
    initialMessagesRef.current = initialMessages;
    callbacksRef.current = { onFinish, onError };

    useEffect(() => {
        generationRef.current++;
        abortRef.current?.abort();
        abortRef.current = null;
        runRef.current = null;
        turnInFlightRef.current = false;
        lastSequenceRef.current = 0;
        setActiveRun(null);
        setConfirmation(null);
        setEvents([]);
        setError(null);
        setStatus('ready');
        setMessages(initialMessagesRef.current ?? []);
    }, [conversationId]);

    // Message history commonly resolves after a newly-created conversation
    // has already started its first turn. Hydrate idle conversations, but do
    // not let that late empty snapshot cancel or overwrite an in-flight run.
    useEffect(() => {
        if (initialMessages !== undefined && !turnInFlightRef.current) {
            setMessages(initialMessages);
        }
    }, [initialMessages]);

    useEffect(() => () => abortRef.current?.abort(), []);

    const recordEvent = useCallback((event: AgentRunEvent) => {
        lastSequenceRef.current = Math.max(lastSequenceRef.current, event.sequence);
        setEvents((current) => {
            if (current.some((item) => item.sequence === event.sequence)) return current;
            return [...current, event].slice(-50);
        });
    }, []);

    const settle = useCallback(async (
        result: AgentRunStreamResult,
        generation: number,
    ): Promise<void> => {
        if (generation !== generationRef.current) return;
        if (result.reason === 'awaiting_confirmation') {
            const extension = result.event.data.extension;
            const values = typeof extension === 'object' && extension !== null
                ? extension as Record<string, unknown>
                : {};
            setConfirmation({
                physicalExtension: Math.max(1, Number(values.physical_extension ?? 25)),
                logicalExtension: Math.max(0, Number(values.logical_extension ?? 0)),
            });
            setStatus('ready');
            return;
        }
        setConfirmation(null);
        if (result.reason === 'failed') {
            throw new Error(result.event.message ?? 'The agent could not complete the search.');
        }
        if (result.reason !== 'cancelled' && conversationId !== null) {
            setMessages(await chatApi.listMessages(conversationId));
        }
        setStatus('ready');
        setActiveRun(null);
        runRef.current = null;
        turnInFlightRef.current = false;
        callbacksRef.current.onFinish?.();
    }, [conversationId]);

    const consume = useCallback(async (
        run: AgentTurnStarted,
        controller: AbortController,
        generation: number,
    ): Promise<void> => {
        const result = await consumeAgentRun({
            initialSequence: lastSequenceRef.current,
            signal: controller.signal,
            open: (after, signal) => fetchAgentEvents(run.events_url, after, signal),
            onEvent: recordEvent,
        });
        await settle(result, generation);
    }, [recordEvent, settle]);

    const reportError = useCallback((reason: unknown, generation: number) => {
        if (generation !== generationRef.current) return;
        if (reason instanceof DOMException && reason.name === 'AbortError') return;
        turnInFlightRef.current = false;
        const next = reason instanceof Error ? reason : new Error(String(reason));
        setError(next);
        setStatus('error');
        callbacksRef.current.onError?.(next);
    }, []);

    const sendMessage = useCallback(async ({ text }: { text: string }): Promise<void> => {
        if (conversationId === null) throw new Error('A conversation is required.');
        const generation = ++generationRef.current;
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;
        setError(null);
        setEvents([]);
        setConfirmation(null);
        setStatus('submitted');
        turnInFlightRef.current = true;
        lastSequenceRef.current = 0;
        try {
            const liveFilters = filtersRef.current;
            const run = await chatApi.startAgentTurn(
                conversationId,
                text,
                isFilterStateEmpty(liveFilters) ? undefined : liveFilters,
            );
            if (generation !== generationRef.current) return;
            runRef.current = run;
            setActiveRun(run);
            setMessages((current) => current.some((item) => item.id === run.user_message.id)
                ? current
                : [...current, run.user_message]);
            setStatus('streaming');
            await consume(run, controller, generation);
        } catch (reason) {
            reportError(reason, generation);
            throw reason;
        }
    }, [consume, conversationId, reportError]);

    const stop = useCallback(() => {
        const run = runRef.current;
        abortRef.current?.abort();
        turnInFlightRef.current = false;
        setStatus('ready');
        if (run) void chatApi.cancelAgentRun(run.cancel_url).catch(() => undefined);
    }, []);

    const continueRun = useCallback(async (): Promise<void> => {
        const run = runRef.current;
        const extension = confirmation;
        if (!run || !extension) return;
        const generation = ++generationRef.current;
        const controller = new AbortController();
        abortRef.current = controller;
        setError(null);
        setStatus('submitted');
        turnInFlightRef.current = true;
        try {
            await chatApi.continueAgentRun(
                run.continue_url,
                extension.physicalExtension,
                extension.logicalExtension,
            );
            setConfirmation(null);
            setStatus('streaming');
            await consume(run, controller, generation);
        } catch (reason) {
            reportError(reason, generation);
            throw reason;
        }
    }, [confirmation, consume, reportError]);

    const regenerate = useCallback(() => {
        const lastUser = [...messages].reverse().find((message) => message.role === 'user');
        if (lastUser) void sendMessage({ text: lastUser.content });
    }, [messages, sendMessage]);

    return {
        messages,
        status,
        error,
        events,
        activeRun,
        confirmation,
        sendMessage,
        stop,
        regenerate,
        continueRun,
        setMessages,
    };
}

async function fetchAgentEvents(url: string, after: number, signal: AbortSignal): Promise<Response> {
    const team = useTeamStore.getState().currentTeam;
    const separator = url.includes('?') ? '&' : '?';
    const headers: Record<string, string> = {
        Accept: 'text/event-stream, application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Last-Event-ID': String(after),
    };
    if (team !== null) headers['X-Tenant-Id'] = team;

    return fetch(`${url}${separator}after=${after}`, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers,
        signal,
    });
}
