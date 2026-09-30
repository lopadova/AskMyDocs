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
const mcpAppId = '01M0Z3DKWB6QXBF5MKB3HZW4HJ';

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

        await act(async () => result.current.sendMessage(
            { text: 'Dammi gli ordini' },
            { mcpAppId },
        ));

        expect(chatApi.startAgentTurn).toHaveBeenCalledWith(
            7,
            'Dammi gli ordini',
            undefined,
            mcpAppId,
            undefined,
            undefined,
            undefined,
        );
        expect(result.current.messages).toEqual([userMessage, assistantMessage]);
        expect(result.current.events.at(-1)?.message).toBe('La risposta è pronta.');
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

    it('passes a structured artifact selection to the agent endpoint', async () => {
        vi.spyOn(chatApi, 'startAgentTurn').mockResolvedValue({
            run_id: 'run-selection',
            status: 'queued',
            locale: 'it-IT',
            events_url: '/agent-runs/run-selection/events',
            cancel_url: '/agent-runs/run-selection/cancel',
            continue_url: '/agent-runs/run-selection/continue',
            user_message: userMessage,
        });
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        vi.stubGlobal('fetch', vi.fn(async () => eventResponse(completedEvent())));
        const { result } = renderHook(() => useAgentChat({
            conversationId: 7,
            filters: {},
            initialMessages: emptyMessages,
        }));

        await act(async () => result.current.sendMessage(
            { text: 'Ho scelto Riccardo Lorini.' },
            { selection: { message_id: 90, row_key: '102' } },
        ));

        expect(chatApi.startAgentTurn).toHaveBeenCalledWith(
            7,
            'Ho scelto Riccardo Lorini.',
            undefined,
            undefined,
            { message_id: 90, row_key: '102' },
            undefined,
            undefined,
        );
    });

    it('passes the current live-source allowlist to every new run', async () => {
        vi.spyOn(chatApi, 'startAgentTurn').mockResolvedValue({
            run_id: 'run-sources', status: 'queued', locale: 'it-IT',
            events_url: '/events', cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage,
        });
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        vi.stubGlobal('fetch', vi.fn(async () => eventResponse(completedEvent())));
        const liveSources = { api: [], mcp: ['mcp:hubhive'] };
        const { result } = renderHook(() => useAgentChat({
            conversationId: 7,
            filters: {},
            liveSources,
            initialMessages: emptyMessages,
        }));

        await act(async () => result.current.sendMessage({ text: 'Usa solo HubHive' }));

        expect(chatApi.startAgentTurn).toHaveBeenCalledWith(
            7,
            'Usa solo HubHive',
            undefined,
            undefined,
            undefined,
            liveSources,
            undefined,
        );
    });

    it('passes the current investigation depth to every new run', async () => {
        vi.spyOn(chatApi, 'startAgentTurn').mockResolvedValue({
            run_id: 'run-depth', status: 'queued', locale: 'it-IT',
            events_url: '/events', cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage,
        });
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        vi.stubGlobal('fetch', vi.fn(async () => eventResponse(completedEvent())));
        const { result } = renderHook(() => useAgentChat({
            conversationId: 7,
            filters: {},
            depth: 5,
            initialMessages: emptyMessages,
        }));

        await act(async () => result.current.sendMessage({ text: 'Analizza a fondo il modulo di fatturazione' }));

        expect(chatApi.startAgentTurn).toHaveBeenCalledWith(
            7,
            'Analizza a fondo il modulo di fatturazione',
            undefined,
            undefined,
            undefined,
            undefined,
            5,
        );
    });

    it('adopts a realtime-created durable run into canonical chat history', async () => {
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        vi.stubGlobal('fetch', vi.fn(async () => eventResponse(completedEvent())));
        const { result } = renderHook(() => useAgentChat({
            conversationId: 7,
            filters: {},
            initialMessages: emptyMessages,
        }));

        await act(async () => result.current.adoptExternalRun({
            run_id: 'run-1',
            status: 'completed',
            locale: 'it-IT',
            events_url: '/agent-runs/run-1/events',
            cancel_url: '/agent-runs/run-1/cancel',
            continue_url: '/agent-runs/run-1/continue',
            user_message: userMessage,
        }));

        expect(result.current.messages).toEqual([userMessage, assistantMessage]);
        expect(result.current.status).toBe('ready');
        expect(chatApi.listMessages).toHaveBeenCalledWith(7);
    });

    it('keeps the original voice run subscribed when adopted twice or another request arrives', async () => {
        const start = vi.spyOn(chatApi, 'startAgentTurn');
        const cancel = vi.spyOn(chatApi, 'cancelAgentRun');
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        let finishEvents!: (response: Response) => void;
        const request = vi.fn((_url: RequestInfo | URL, _init?: RequestInit) => new Promise<Response>((resolve) => { finishEvents = resolve; }));
        vi.stubGlobal('fetch', request);
        const { result } = renderHook(() => useAgentChat({ conversationId: 7, filters: {}, initialMessages: emptyMessages }));
        const run = {
            run_id: 'run-1', status: 'running', locale: 'it-IT',
            events_url: '/events', cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage,
        };
        let observing!: Promise<void>;
        act(() => { observing = result.current.adoptExternalRun(run); });
        await waitFor(() => expect(result.current.status).toBe('streaming'));
        await act(async () => {
            await result.current.adoptExternalRun(run);
            await expect(result.current.sendMessage({ text: 'Un altro ordine' })).rejects.toThrow('una cosa alla volta');
            await expect(result.current.adoptExternalRun({ ...run, run_id: 'another-run' })).rejects.toThrow('already in progress');
        });
        expect(request).toHaveBeenCalledOnce();
        expect(request.mock.calls[0]?.[1]?.signal?.aborted).toBe(false);
        expect(start).not.toHaveBeenCalled();
        expect(cancel).not.toHaveBeenCalled();
        await act(async () => { finishEvents(eventResponse(completedEvent())); await observing; });
        expect(result.current.messages).toEqual([userMessage, assistantMessage]);
        expect(result.current.status).toBe('ready');
    });

    it('retains the question/task list across a long activity stream', async () => {
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        const planned: AgentRunEvent = { ...completedEvent(), sequence: 1, type: 'research.planned',
            data: { tasks: [{ id: 'customer', question: 'Chi è il cliente?' }, { id: 'orders', question: 'Quali sono gli ordini?' }] } };
        const events = [planned, ...Array.from({ length: 60 }, (_, index) => ({
            ...completedEvent(), sequence: index + 2, type: 'tool.started', data: {},
        })), { ...completedEvent(), sequence: 62 }];
        vi.stubGlobal('fetch', vi.fn(async () => new Response(events.map((event) =>
            `id: ${event.sequence}\nevent: ${event.type}\ndata: ${JSON.stringify(event)}\n\n`).join(''),
        { headers: { 'Content-Type': 'text/event-stream' } })));
        const { result } = renderHook(() => useAgentChat({ conversationId: 7, filters: {}, initialMessages: emptyMessages }));
        await act(async () => result.current.adoptExternalRun({
            run_id: 'run-1', status: 'running', locale: 'it-IT',
            events_url: '/events', cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage,
        }));
        expect(result.current.events[0]).toEqual(planned);
        expect(result.current.events).toHaveLength(51);
        expect(result.current.events.at(-1)?.type).toBe('run.completed');
    });

    it('can recover a failed early subscription from the final voice receipt', async () => {
        vi.spyOn(chatApi, 'listMessages').mockRejectedValueOnce(new Error('Temporary history failure'))
            .mockResolvedValueOnce([userMessage, assistantMessage]);
        vi.stubGlobal('fetch', vi.fn(async () => eventResponse(completedEvent())));
        const { result } = renderHook(() => useAgentChat({ conversationId: 7, filters: {}, initialMessages: emptyMessages }));
        const run = { run_id: 'run-1', status: 'completed', locale: 'it-IT',
            events_url: '/events', cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage };
        await act(async () => { await expect(result.current.adoptExternalRun(run)).rejects.toThrow('Temporary history failure'); });
        expect(result.current.status).toBe('error');
        await act(async () => result.current.adoptExternalRun(run));
        expect(result.current.messages).toEqual([userMessage, assistantMessage]);
        expect(result.current.status).toBe('ready');
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
