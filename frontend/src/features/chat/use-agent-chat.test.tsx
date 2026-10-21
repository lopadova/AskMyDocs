import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { chatApi, type Message } from './chat.api';
import { useAgentChat } from './use-agent-chat';
import { clearAgentChatSessions, getAgentChatSession } from './agent-chat-session';
import { useTeamStore } from '../../lib/team-store';

beforeEach(() => clearAgentChatSessions());
afterEach(() => {
    clearAgentChatSessions();
    useTeamStore.setState({ userId: null, currentTeam: null });
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

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
    it('restores confirmation after navigation and resumes the same run after a recoverable continuation error', async () => {
        const run = { run_id: 'run-1', status: 'queued', locale: 'it', events_url: '/events',
            cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage };
        vi.spyOn(chatApi, 'continueAgentRun').mockRejectedValueOnce(new Error('Temporary network failure')).mockResolvedValue();
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        const request = vi.fn().mockResolvedValueOnce(eventResponse({
            ...completedEvent(), sequence: 1, type: 'run.awaiting_confirmation',
            data: { extension: { physical_extension: 30, logical_extension: 2 } },
        })).mockResolvedValueOnce(eventResponse(completedEvent()));
        vi.stubGlobal('fetch', request);
        const { result, rerender } = renderHook(({ id }) => useAgentChat({ conversationId: id, filters: {} }), { initialProps: { id: 7 } });
        await act(async () => result.current.adoptExternalRun(run));
        rerender({ id: 8 });
        expect(result.current.confirmation).toBeNull();
        rerender({ id: 7 });
        expect(result.current.confirmation).toEqual({ physicalExtension: 30, logicalExtension: 2 });
        await act(async () => { await expect(result.current.continueRun()).rejects.toThrow('Temporary network failure'); });
        expect(result.current.confirmation).toEqual({ physicalExtension: 30, logicalExtension: 2 });
        await act(async () => result.current.continueRun());
        expect(chatApi.continueAgentRun).toHaveBeenCalledWith('/continue', 30, 2);
        expect(request.mock.calls[1]?.[0]).toBe('/events?after=1');
        expect(result.current.messages).toEqual([userMessage, assistantMessage]);
    });

    it('marks a reply unread when navigation happens before its delayed history reaches the screen', async () => {
        let finishHistory!: (messages: Message[]) => void;
        vi.spyOn(chatApi, 'listMessages').mockImplementation(() => new Promise((resolve) => { finishHistory = resolve; }));
        vi.stubGlobal('fetch', vi.fn(async () => eventResponse(completedEvent())));
        const { result, rerender } = renderHook(({ id }) => useAgentChat({ conversationId: id, filters: {} }), { initialProps: { id: 7 } });
        let observing!: Promise<void>;
        act(() => { observing = result.current.adoptExternalRun({
            run_id: 'run-1', status: 'queued', locale: 'it', events_url: '/events',
            cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage,
        }); });
        await waitFor(() => expect(chatApi.listMessages).toHaveBeenCalledOnce());
        expect(result.current.messages).toEqual([userMessage]);
        rerender({ id: 8 });
        await act(async () => { finishHistory([userMessage, assistantMessage]); await observing; });
        expect(getAgentChatSession(7, null, null).store.getState().unreadAt).not.toBeNull();
        expect(result.current.messages).toEqual([]);
    });

    it('cancels a run even when Stop is pressed before its startup request resolves', async () => {
        let start!: (value: Awaited<ReturnType<typeof chatApi.startAgentTurn>>) => void;
        vi.spyOn(chatApi, 'startAgentTurn').mockImplementation(() => new Promise((resolve) => { start = resolve; }));
        const cancel = vi.spyOn(chatApi, 'cancelAgentRun').mockResolvedValue();
        const request = vi.fn();
        vi.stubGlobal('fetch', request);
        const { result } = renderHook(() => useAgentChat({ conversationId: 7, filters: {} }));
        let sending!: Promise<void>;
        act(() => { sending = result.current.sendMessage({ text: 'Question' }); });
        act(() => result.current.stop());
        await act(async () => {
            start({ run_id: 'run-1', status: 'queued', locale: 'it', events_url: '/events',
                cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage });
            await sending;
        });
        expect(cancel).toHaveBeenCalledWith('/cancel');
        expect(request).not.toHaveBeenCalled();
        expect(result.current.status).toBe('ready');
    });

    it('discards a logged-out user’s background state and ignores a late startup response', async () => {
        useTeamStore.setState({ userId: 1, currentTeam: 'acme' });
        let start!: (value: Awaited<ReturnType<typeof chatApi.startAgentTurn>>) => void;
        vi.spyOn(chatApi, 'startAgentTurn').mockImplementation(() => new Promise((resolve) => { start = resolve; }));
        const onFinish = vi.fn();
        const request = vi.fn();
        vi.stubGlobal('fetch', request);
        const { result } = renderHook(() => useAgentChat({ conversationId: 7, filters: {}, onFinish }));
        const oldSession = getAgentChatSession(7, 1, 'acme');
        let sending!: Promise<void>;
        act(() => { sending = result.current.sendMessage({ text: 'Question' }); });
        act(() => useTeamStore.getState().clear());
        await act(async () => {
            start({ run_id: 'run-1', status: 'queued', locale: 'it', events_url: '/events',
                cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage });
            await sending;
        });
        expect(getAgentChatSession(7, 1, 'acme')).not.toBe(oldSession);
        expect(result.current.messages).toEqual([]);
        expect(request).not.toHaveBeenCalled();
        expect(onFinish).not.toHaveBeenCalled();
    });

    it('keeps startup and simultaneous runs independent when navigating, then reads the background reply on return', async () => {
        const run = (id: number) => ({
            run_id: `run-${id}`, status: 'queued', locale: 'it',
            events_url: `/runs/${id}/events`, cancel_url: `/runs/${id}/cancel`, continue_url: `/runs/${id}/continue`,
            user_message: { ...userMessage, id: id * 10, content: `Question ${id}` },
        });
        let startFirst!: (value: ReturnType<typeof run>) => void;
        vi.spyOn(chatApi, 'startAgentTurn').mockImplementation((id) => id === 7
            ? new Promise((resolve) => { startFirst = resolve; }) : Promise.resolve(run(id)));
        vi.spyOn(chatApi, 'listMessages').mockImplementation(async (id) => [run(id).user_message, {
            ...assistantMessage, id: id * 10 + 1, content: `Answer ${id}`,
        }]);
        const cancel = vi.spyOn(chatApi, 'cancelAgentRun');
        const streams = new Map<string, ReadableStreamDefaultController<Uint8Array>>();
        const request = vi.fn(async (url: RequestInfo | URL) => new Response(new ReadableStream<Uint8Array>({
            start(controller) { streams.set(String(url), controller); },
        })));
        vi.stubGlobal('fetch', request);
        const firstFinish = vi.fn();
        const secondFinish = vi.fn();
        const { result, rerender } = renderHook(({ id }) => useAgentChat({
            conversationId: id, filters: {}, initialMessages: emptyMessages,
            onFinish: id === 7 ? firstFinish : secondFinish,
        }), { initialProps: { id: 7 as number | null } });

        let first!: Promise<void>;
        act(() => { first = result.current.sendMessage({ text: 'First' }); });
        rerender({ id: null });
        expect(result.current.status).toBe('ready');
        expect(result.current.messages).toEqual([]);
        rerender({ id: 8 });
        let second!: Promise<void>;
        act(() => { second = result.current.sendMessage({ text: 'Second' }); });
        await act(async () => startFirst(run(7)));
        await waitFor(() => expect(streams.size).toBe(2));
        expect(result.current.messages).toEqual([run(8).user_message]);
        expect(getAgentChatSession(7, null, null).store.getState().status).toBe('streaming');
        const answeredAt = '2026-10-21T11:00:00Z';
        await act(async () => {
            streams.get('/runs/7/events?after=0')!.enqueue(new TextEncoder().encode(
                `data: ${JSON.stringify({ ...completedEvent(), created_at: answeredAt })}\n\n`,
            ));
            await first;
        });
        expect(firstFinish).toHaveBeenCalledOnce();
        expect(secondFinish).not.toHaveBeenCalled();
        expect(getAgentChatSession(7, null, null).store.getState().unreadAt).toBe(Date.parse(answeredAt));
        expect(result.current.activeRun?.run_id).toBe('run-8');
        expect(result.current.messages).not.toContainEqual(expect.objectContaining({ content: 'Answer 7' }));

        rerender({ id: 7 });
        expect(result.current.messages.at(-1)?.content).toBe('Answer 7');
        expect(getAgentChatSession(7, null, null).store.getState().unreadAt).toBeNull();
        await act(async () => {
            streams.get('/runs/8/events?after=0')!.enqueue(new TextEncoder().encode(
                `data: ${JSON.stringify(completedEvent())}\n\n`,
            ));
            await second;
        });
        expect(result.current.messages.at(-1)?.content).toBe('Answer 7');
        expect(cancel).not.toHaveBeenCalled();
        expect(chatApi.startAgentTurn).toHaveBeenCalledTimes(2);
    });

    it('retains run progress across unmounts and remounts without starting another subscription', async () => {
        const run = { run_id: 'run-1', status: 'queued', locale: 'it', events_url: '/events',
            cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage };
        vi.spyOn(chatApi, 'startAgentTurn').mockResolvedValue(run);
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        let stream!: ReadableStreamDefaultController<Uint8Array>;
        let signal!: AbortSignal;
        const request = vi.fn(async (_url: RequestInfo | URL, init?: RequestInit) => {
            signal = init!.signal!;
            return new Response(new ReadableStream<Uint8Array>({ start(controller) { stream = controller; } }));
        });
        vi.stubGlobal('fetch', request);
        const options = { conversationId: 7, filters: {}, initialMessages: emptyMessages };
        const firstView = renderHook(() => useAgentChat(options));
        let sending!: Promise<void>;
        act(() => { sending = firstView.result.current.sendMessage({ text: 'Orders' }); });
        await waitFor(() => expect(request).toHaveBeenCalledOnce());
        const progress = { ...completedEvent(), sequence: 1, type: 'tool.started' };
        await act(async () => stream.enqueue(new TextEncoder().encode(`data: ${JSON.stringify(progress)}\n\n`)));
        firstView.unmount();
        expect(signal.aborted).toBe(false);
        const secondView = renderHook(() => useAgentChat(options));
        expect(secondView.result.current.status).toBe('streaming');
        expect(secondView.result.current.events).toEqual([progress]);
        expect(request).toHaveBeenCalledOnce();
        secondView.unmount();
        await act(async () => {
            stream.enqueue(new TextEncoder().encode(`data: ${JSON.stringify(completedEvent())}\n\n`));
            await sending;
        });
        expect(getAgentChatSession(7, null, null).store.getState().unreadAt).not.toBeNull();
        const thirdView = renderHook(() => useAgentChat(options));
        expect(thirdView.result.current.messages).toEqual([userMessage, assistantMessage]);
        expect(getAgentChatSession(7, null, null).store.getState().unreadAt).toBeNull();
    });

    it('pins a background run and history to their owning tenant after a team switch', async () => {
        useTeamStore.setState({ userId: 1, currentTeam: 'acme' });
        vi.spyOn(chatApi, 'startAgentTurn').mockResolvedValue({
            run_id: 'run-1', status: 'queued', locale: 'it', events_url: '/events',
            cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage,
        });
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([userMessage, assistantMessage]);
        let finish!: (response: Response) => void;
        const request = vi.fn((_url: RequestInfo | URL, _init?: RequestInit) => new Promise<Response>((resolve) => { finish = resolve; }));
        vi.stubGlobal('fetch', request);
        const { result } = renderHook(() => useAgentChat({ conversationId: 7, filters: {} }));
        let sending!: Promise<void>;
        act(() => { sending = result.current.sendMessage({ text: 'Orders' }); });
        await waitFor(() => expect(request).toHaveBeenCalledOnce());
        act(() => useTeamStore.setState({ currentTeam: 'globex' }));
        expect(result.current.messages).toEqual([]);
        expect(request.mock.calls[0]?.[1]?.headers).toMatchObject({ 'X-Tenant-Id': 'acme' });
        await act(async () => { finish(eventResponse(completedEvent())); await sending; });
        expect(chatApi.listMessages).toHaveBeenCalledWith(7, 'acme');
        expect(result.current.messages).toEqual([]);
        expect(getAgentChatSession(7, 1, 'acme').store.getState().unreadAt).not.toBeNull();
    });

    it.each(['run.failed', 'run.cancelled'])('does not mark %s as an unread answer', async (type) => {
        vi.spyOn(chatApi, 'listMessages').mockResolvedValue([]);
        let finish!: (response: Response) => void;
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => { finish = resolve; })));
        const { result, unmount } = renderHook(() => useAgentChat({ conversationId: 7, filters: {} }));
        const run = { run_id: 'run-1', status: 'queued', locale: 'it', events_url: '/events',
            cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage };
        let observing!: Promise<unknown>;
        act(() => { observing = result.current.adoptExternalRun(run).catch((error) => error); });
        unmount();
        await act(async () => { finish(eventResponse({ ...completedEvent(), type })); await observing; });
        expect(getAgentChatSession(7, null, null).store.getState().unreadAt).toBeNull();
        expect(getAgentChatSession(7, null, null).store.getState().status).toBe(type === 'run.failed' ? 'error' : 'ready');
    });

    it('releases a completed voice turn before history refresh and ignores a late snapshot after the next request starts', async () => {
        const first = { run_id: 'run-1', status: 'running', locale: 'it', events_url: '/agent-runs/run-1/events',
            cancel_url: '/cancel', continue_url: '/continue', user_message: userMessage };
        const secondUser = { ...userMessage, id: 12, content: 'Quali sono le spedizioni?' };
        const second = { ...first, run_id: 'run-2', events_url: '/agent-runs/run-2/events', user_message: secondUser };
        let refreshFirst!: (messages: Message[]) => void;
        vi.spyOn(chatApi, 'cancelAgentRun').mockResolvedValue(undefined);
        vi.spyOn(chatApi, 'listMessages').mockImplementation(() => new Promise((resolve) => { refreshFirst = resolve; }));
        vi.stubGlobal('fetch', vi.fn(async (url: RequestInfo | URL) => String(url).includes('run-1')
            ? eventResponse(completedEvent())
            : new Response(new ReadableStream<Uint8Array>())));
        const { result } = renderHook(() => useAgentChat({ conversationId: 7, filters: {}, initialMessages: emptyMessages }));
        let observeFirst!: Promise<void>;
        await act(async () => { observeFirst = result.current.adoptExternalRun(first); });
        await waitFor(() => expect(chatApi.listMessages).toHaveBeenCalledOnce());
        expect(result.current.status).toBe('ready');
        let observeSecond!: Promise<void>;
        await act(async () => { observeSecond = result.current.adoptExternalRun(second).catch((error) => {
            if (error.name !== 'AbortError') throw error;
        }); });
        await waitFor(() => expect(result.current.activeRun?.run_id).toBe('run-2'));
        await act(async () => { refreshFirst([userMessage, assistantMessage]); await observeFirst; });
        expect(result.current.status).toBe('streaming');
        expect(result.current.activeRun?.run_id).toBe('run-2');
        expect(result.current.messages).toContainEqual(secondUser);
        expect(result.current.messages).not.toContainEqual(assistantMessage);
        await act(async () => { result.current.stop(); await observeSecond; });
    });

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
