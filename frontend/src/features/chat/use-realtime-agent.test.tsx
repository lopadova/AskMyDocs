import { act, renderHook, waitFor } from '@testing-library/react';
import { FakeRealtimeDriver, type ToolResult } from '@agents-full-duplex/realtime-agent-client';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useTeamStore } from '../../lib/team-store';
import { chatApi, type AgentTurnStarted, type RealtimeAgentConnection } from './chat.api';
import { useRealtimeAgent } from './use-realtime-agent';

const state = {
    schema: 'realtime-agent-state@1' as const,
    session: { id: '01KSESSION', revision: 1, status: 'active' },
    pending: { actions: [], confirmations: [] },
};

const liveRun: AgentTurnStarted = {
    run_id: 'voice-run-1', status: 'running', locale: 'it',
    events_url: '/agent-runs/voice-run-1/events', cancel_url: '/cancel', continue_url: '/continue',
    user_message: { id: 10, role: 'user', content: 'Cerca il cliente e gli ordini', metadata: {}, rating: null, created_at: '' },
};
const descriptor: RealtimeAgentConnection = {
    session_id: '01KSESSION', provider: 'fake', connection: { transport: 'fake' }, state,
    conversation_id: 7, expires_at: '2026-09-15T16:00:00Z',
    busy_message: 'Un attimo, una cosa alla volta. Sto ancora completando la richiesta precedente.',
};
const call = (id: string) => ({ id, name: 'askmydocs.chat_turn', arguments: { question: 'Cerca il cliente e gli ordini' }, base_revision: 1 });
const json = (body: unknown) => new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } });
const toolResult = (id: string): ToolResult => ({
    call_id: id, status: 'completed', state_revision: 2,
    output: { run: liveRun as unknown as ToolResult['output'], response: { answer: 'Ecco il cliente e gli ordini.' } },
});

function captureDriver() {
    let driver: FakeRealtimeDriver;
    const connect = FakeRealtimeDriver.prototype.connect;
    vi.spyOn(FakeRealtimeDriver.prototype, 'connect').mockImplementation(async function (this: FakeRealtimeDriver, connection) {
        driver = this;
        await connect.call(this, connection);
    });
    return () => driver;
}

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    useTeamStore.getState().clear();
});

describe('useRealtimeAgent', () => {
    it('shows the pending voice run immediately, refuses overlap, and still delivers the original spoken answer', async () => {
        useTeamStore.setState({ currentTeam: 'acme' });
        vi.spyOn(chatApi, 'startRealtimeAgent').mockResolvedValue(descriptor);
        const driver = captureDriver();
        const disconnected = vi.spyOn(FakeRealtimeDriver.prototype, 'disconnect');
        let finishTool!: (response: Response) => void;
        const tool = vi.fn(() => new Promise<Response>((resolve) => { finishTool = resolve; }));
        const request = vi.fn(async (url: RequestInfo | URL, _init?: RequestInit) => {
            if (String(url).includes('/run?')) return json({ run: liveRun });
            if (String(url).endsWith('/tools')) return tool();
            return json({ state });
        });
        vi.stubGlobal('fetch', request);
        // The SSE UI may still be draining/recovering when the canonical answer arrives.
        const onAdoptRun = vi.fn(() => new Promise<void>(() => undefined));
        const { result, rerender } = renderHook(({ busy }) => useRealtimeAgent({
            conversationId: 7, filters: {}, availability: { available: true, reason: null },
            onRequireConversation: vi.fn().mockResolvedValue(7), onAdoptRun, requestInFlight: busy,
        }), { initialProps: { busy: false } });

        await act(async () => result.current.start());
        act(() => driver().emit({ type: 'agent.tool.call', call: call('first') }));
        await waitFor(() => expect(onAdoptRun).toHaveBeenCalledWith(liveRun));
        expect(driver().toolResults).toHaveLength(0);
        expect(result.current.status).toBe('processing');
        const receipt = request.mock.calls.find(([url]) => String(url).includes('/run?'));
        expect(String(receipt?.[0])).toContain('call_id=first');
        expect(new Headers(receipt?.[1]?.headers).get('X-Tenant-Id')).toBe('acme');

        rerender({ busy: true });
        act(() => driver().emit({ type: 'agent.tool.call', call: call('second') }));
        await waitFor(() => expect(driver().toolResults).toHaveLength(1));
        expect(driver().toolResults[0]).toMatchObject({ call_id: 'second', output: {
            busy: true, retry: false, response: { answer: descriptor.busy_message },
        } });
        expect(tool).toHaveBeenCalledOnce();
        expect(disconnected).not.toHaveBeenCalled();

        await act(async () => finishTool(json({ result: toolResult('first') })));
        await waitFor(() => expect(driver().toolResults).toHaveLength(2));
        expect(driver().toolResults[1]).toEqual(toolResult('first'));
        expect(onAdoptRun).toHaveBeenCalledOnce();

        // Even after the tool returns, an active text/UI turn keeps the gate closed.
        act(() => driver().emit({ type: 'agent.tool.call', call: call('third') }));
        await waitFor(() => expect(driver().toolResults).toHaveLength(3));
        expect(tool).toHaveBeenCalledOnce();
        rerender({ busy: false });
        act(() => driver().emit({ type: 'agent.tool.call', call: call('fourth') }));
        await waitFor(() => expect(tool).toHaveBeenCalledTimes(2));
        await act(async () => finishTool(json({ result: toolResult('fourth') })));
        await waitFor(() => expect(driver().toolResults).toHaveLength(4));
        await act(async () => result.current.stop());
    });

    it('stops receipt observation on hangup and ignores late tool/receipt callbacks', async () => {
        vi.spyOn(chatApi, 'startRealtimeAgent').mockResolvedValue(descriptor);
        const driver = captureDriver();
        let finishTool!: (response: Response) => void;
        let finishReceipt!: (response: Response) => void;
        let receiptSignal: AbortSignal | null | undefined;
        vi.stubGlobal('fetch', vi.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
            if (String(url).includes('/run?')) {
                receiptSignal = init?.signal;
                return new Promise<Response>((resolve) => { finishReceipt = resolve; });
            }
            if (String(url).endsWith('/tools')) return new Promise<Response>((resolve) => { finishTool = resolve; });
            return json({ state });
        }));
        const onAdoptRun = vi.fn().mockResolvedValue(undefined);
        const { result } = renderHook(() => useRealtimeAgent({
            conversationId: 7, filters: {}, availability: { available: true, reason: null },
            onRequireConversation: vi.fn(), onAdoptRun,
        }));
        await act(async () => result.current.start());
        act(() => driver().emit({ type: 'agent.tool.call', call: call('first') }));
        await waitFor(() => expect(receiptSignal).toBeDefined());
        await act(async () => result.current.stop());
        expect(receiptSignal?.aborted).toBe(true);
        await act(async () => {
            finishReceipt(json({ run: liveRun }));
            finishTool(json({ result: toolResult('first') }));
        });
        expect(onAdoptRun).not.toHaveBeenCalled();
        expect(driver().toolResults).toHaveLength(0);
        expect(result.current.status).toBe('idle');
        expect(result.current.error).toBeNull();
    });

    it('delivers the canonical answer even if early receipt observation fails', async () => {
        vi.spyOn(chatApi, 'startRealtimeAgent').mockResolvedValue(descriptor);
        const driver = captureDriver();
        vi.stubGlobal('fetch', vi.fn(async (url: RequestInfo | URL) => {
            if (String(url).includes('/run?')) return new Response(null, { status: 403 });
            if (String(url).endsWith('/tools')) return json({ result: toolResult('first') });
            return json({ state });
        }));
        const onAdoptRun = vi.fn().mockResolvedValue(undefined);
        const { result } = renderHook(() => useRealtimeAgent({
            conversationId: 7, filters: {}, availability: { available: true, reason: null },
            onRequireConversation: vi.fn(), onAdoptRun,
        }));
        await act(async () => result.current.start());
        act(() => driver().emit({ type: 'agent.tool.call', call: call('first') }));
        await waitFor(() => expect(driver().toolResults).toHaveLength(1));
        expect(onAdoptRun).toHaveBeenCalledWith(liveRun);
        expect(result.current.error).toBeNull();
        await act(async () => result.current.stop());
    });

    it('connects the Fake driver and tenant-scopes every control request', async () => {
        useTeamStore.setState({ currentTeam: 'acme' });
        const descriptor: RealtimeAgentConnection = {
            session_id: '01KSESSION',
            provider: 'fake',
            connection: { transport: 'fake' },
            state,
            conversation_id: 7,
            expires_at: '2026-09-15T16:00:00Z',
        };
        vi.spyOn(chatApi, 'startRealtimeAgent').mockResolvedValue(descriptor);
        const request = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({ state }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }));
        vi.stubGlobal('fetch', request);
        const onRequireConversation = vi.fn().mockResolvedValue(7);
        const onAdoptRun = vi.fn().mockResolvedValue(undefined);
        const filters = { languages: ['it'] };
        const liveSources = { api: [], mcp: ['mcp:search'] };
        const { result } = renderHook(() => useRealtimeAgent({
            conversationId: 7,
            filters,
            liveSources,
            availability: { available: true, reason: null },
            onRequireConversation,
            onAdoptRun,
        }));

        await act(async () => result.current.start());

        expect(result.current.status).toBe('listening');
        expect(result.current.active).toBe(true);
        expect(onRequireConversation).not.toHaveBeenCalled();
        expect(chatApi.startRealtimeAgent).toHaveBeenCalledWith(7, filters, liveSources);
        const firstInit = request.mock.calls[0]?.[1] as RequestInit;
        expect(new Headers(firstInit.headers).get('X-Tenant-Id')).toBe('acme');
        expect(new Headers(firstInit.headers).get('X-Requested-With')).toBe('XMLHttpRequest');

        await act(async () => result.current.stop());
        expect(result.current.status).toBe('idle');
        expect(request.mock.calls.some(([, init]) => (init as RequestInit).method === 'DELETE')).toBe(true);
    });

    it('explains denied microphone access and finishes the failed server session', async () => {
        const descriptor: RealtimeAgentConnection = {
            session_id: '01KSESSION',
            provider: 'fake',
            connection: { transport: 'fake' },
            state,
            conversation_id: 7,
            expires_at: '2026-09-15T16:00:00Z',
        };
        vi.spyOn(chatApi, 'startRealtimeAgent').mockResolvedValue(descriptor);
        vi.spyOn(FakeRealtimeDriver.prototype, 'connect').mockRejectedValue(
            new DOMException('Permission denied', 'NotAllowedError'),
        );
        const finishedState = {
            ...state,
            session: { ...state.session, revision: 2, status: 'finished' },
        };
        const request = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(
            JSON.stringify({ state: finishedState }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
        ));
        vi.stubGlobal('fetch', request);
        const { result } = renderHook(() => useRealtimeAgent({
            conversationId: 7,
            filters: {},
            availability: { available: true, reason: null },
            onRequireConversation: vi.fn().mockResolvedValue(7),
            onAdoptRun: vi.fn().mockResolvedValue(undefined),
        }));

        await act(async () => {
            await expect(result.current.start()).rejects.toThrow(
                'Microphone access was denied. Allow microphone access for this site in your browser settings, then try again.',
            );
        });

        expect(result.current.status).toBe('error');
        expect(result.current.active).toBe(false);
        expect(result.current.error?.message).toContain('Allow microphone access');
        expect(request.mock.calls.some(([, init]) => (init as RequestInit).method === 'DELETE')).toBe(true);
    });

    it('finishes the server session when OpenAI microphone preflight is denied', async () => {
        const descriptor: RealtimeAgentConnection = {
            session_id: '01KSESSION',
            provider: 'openai',
            connection: {
                transport: 'webrtc',
                api_variant: 'live',
                bootstrap_url: '/realtime-agent/sessions/01KSESSION/connect',
            },
            state,
            conversation_id: 7,
            expires_at: '2026-09-15T16:00:00Z',
        };
        vi.spyOn(chatApi, 'startRealtimeAgent').mockResolvedValue(descriptor);
        vi.stubGlobal('navigator', {
            mediaDevices: {
                getUserMedia: vi.fn().mockRejectedValue(
                    new DOMException('Permission denied', 'NotAllowedError'),
                ),
            },
        });
        const request = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({
            state: {
                ...state,
                session: { ...state.session, revision: 2, status: 'finished' },
            },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        vi.stubGlobal('fetch', request);
        const { result } = renderHook(() => useRealtimeAgent({
            conversationId: 7,
            filters: {},
            availability: { available: true, reason: null },
            onRequireConversation: vi.fn().mockResolvedValue(7),
            onAdoptRun: vi.fn().mockResolvedValue(undefined),
        }));

        await act(async () => {
            await expect(result.current.start()).rejects.toThrow('Microphone access was denied.');
        });

        expect(result.current.status).toBe('error');
        expect(request.mock.calls.filter(([, init]) => (init as RequestInit).method === 'DELETE')).toHaveLength(1);
    });
});
