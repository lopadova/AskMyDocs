import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { chatApi, type Message } from './chat.api';
import { useAgentChat } from './use-agent-chat';

afterEach(() => vi.restoreAllMocks());

const userMessage: Message = {
    id: 10,
    role: 'user',
    content: 'Dammi gli ordini',
    metadata: {},
    rating: null,
    created_at: '2026-08-08T12:00:00Z',
};
const assistantMessage: Message = {
    id: 11,
    role: 'assistant',
    content: 'Ordine A-100',
    metadata: { tool_calls: [{ id: 'tool-1', name: 'list_orders', status: 'ok' }] },
    rating: null,
    created_at: '2026-08-08T12:00:01Z',
};
const emptyMessages: Message[] = [];

function completedEvent(): AgentRunEvent {
    return {
        run_id: 'run-1',
        sequence: 2,
        type: 'run.completed',
        phase: 'run',
        locale: 'it-IT',
        message_key: 'run.completed',
        message_params: {},
        message: 'La risposta è pronta.',
        progress: null,
        can_cancel: false,
        data: { response: { answer: 'Ordine A-100' } },
        created_at: null,
    };
}

function eventResponse(event: AgentRunEvent): Response {
    return new Response(`id: ${event.sequence}\nevent: ${event.type}\ndata: ${JSON.stringify(event)}\n\n`, {
        status: 200,
        headers: { 'Content-Type': 'text/event-stream' },
    });
}

describe('useAgentChat', () => {
    it('starts a durable turn, consumes localized events and reloads canonical messages', async () => {
        vi.spyOn(chatApi, 'startAgentTurn').mockResolvedValue({
            run_id: 'run-1',
            status: 'queued',
            locale: 'it-IT',
            events_url: '/agent-runs/run-1/events',
            cancel_url: '/agent-runs/run-1/cancel',
            continue_url: '/agent-runs/run-1/continue',
            user_message: userMessage,
        });
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        vi.stubGlobal('fetch', vi.fn(async () => eventResponse(completedEvent())));
        const onFinish = vi.fn();
        const { result } = renderHook(() => useAgentChat({
            conversationId: 7,
            filters: {},
            initialMessages: emptyMessages,
            onFinish,
        }));

        await act(async () => result.current.sendMessage({ text: 'Dammi gli ordini' }));

        expect(chatApi.startAgentTurn).toHaveBeenCalledWith(7, 'Dammi gli ordini', undefined);
        expect(result.current.messages).toEqual([userMessage, assistantMessage]);
        expect(result.current.events.at(-1)?.message).toBe('La risposta è pronta.');
        expect(result.current.activityMessageId).toBe(userMessage.id);
        expect(result.current.status).toBe('ready');
        expect(onFinish).toHaveBeenCalledOnce();
    });

    it('does not cancel a first turn when the empty history resolves during startup', async () => {
        let resolveStart: ((value: Awaited<ReturnType<typeof chatApi.startAgentTurn>>) => void) | undefined;
        vi.spyOn(chatApi, 'startAgentTurn').mockImplementation(() => new Promise((resolve) => { resolveStart = resolve; }));
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        const eventFetch = vi.fn(async () => eventResponse(completedEvent()));
        vi.stubGlobal('fetch', eventFetch);

        const { result, rerender } = renderHook(
            ({ history }: { history: Message[] | undefined }) => useAgentChat({
                conversationId: 7,
                filters: {},
                initialMessages: history,
            }),
            { initialProps: { history: undefined as Message[] | undefined } },
        );

        let sending!: Promise<void>;
        act(() => { sending = result.current.sendMessage({ text: 'Dammi gli ordini' }); });
        rerender({ history: [] });
        act(() => resolveStart?.({
            run_id: 'run-1', status: 'queued', locale: 'it-IT',
            events_url: '/agent-runs/run-1/events', cancel_url: '/cancel', continue_url: '/continue',
            user_message: userMessage,
        }));

        await act(async () => sending);

        expect(eventFetch).toHaveBeenCalledOnce();
        expect(result.current.messages).toEqual([userMessage, assistantMessage]);
        expect(result.current.events.at(-1)?.type).toBe('run.completed');
    });

    it('cancels the current backend run when stopped', async () => {
        let resolveStart: ((value: Awaited<ReturnType<typeof chatApi.startAgentTurn>>) => void) | undefined;
        vi.spyOn(chatApi, 'startAgentTurn').mockImplementation(() => new Promise((resolve) => { resolveStart = resolve; }));
        const cancel = vi.spyOn(chatApi, 'cancelAgentRun').mockResolvedValue();
        vi.stubGlobal('fetch', vi.fn((_url: string, init?: RequestInit) => new Promise<Response>((_resolve, reject) => {
            init?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true });
        })));
        const { result } = renderHook(() => useAgentChat({ conversationId: 7, filters: {}, initialMessages: emptyMessages }));
        let sending!: Promise<void>;
        act(() => { sending = result.current.sendMessage({ text: 'Ordini' }); });
        const settled = sending.catch((reason: unknown) => reason);
        act(() => resolveStart?.({
            run_id: 'run-2', status: 'queued', locale: 'it-IT',
            events_url: '/events', cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage,
        }));
        await waitFor(() => expect(result.current.activeRun?.run_id).toBe('run-2'));

        act(() => result.current.stop());
        await waitFor(() => expect(cancel).toHaveBeenCalledWith('/cancel'));
        await expect(settled).resolves.toMatchObject({ name: 'AbortError' });
        expect(result.current.status).toBe('ready');
    });
});
