import { act, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { FakeRealtimeDriver } from '@agents-full-duplex/realtime-agent-client';
import { afterEach, expect, it, vi } from 'vitest';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { chatApi, type AgentTurnStarted, type Message } from './chat.api';
import { MessageThread } from './MessageThread';
import { useAgentChat } from './use-agent-chat';
import { useRealtimeAgent, type UseRealtimeAgentResult } from './use-realtime-agent';

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

it('renders the voice request and multiple task states before the spoken result is available', async () => {
    const state = { schema: 'realtime-agent-state@1' as const,
        session: { id: 'voice-session', revision: 1, status: 'active' }, pending: { actions: [], confirmations: [] } };
    vi.spyOn(chatApi, 'startRealtimeAgent').mockResolvedValue({ session_id: 'voice-session', provider: 'fake',
        connection: { transport: 'fake' }, state, conversation_id: 7, expires_at: '' });
    Object.defineProperty(HTMLElement.prototype, 'scrollTo', { configurable: true, value: vi.fn() });
    const user: Message = { id: 10, role: 'user', content: 'Cerca il cliente e gli ordini', rating: null, created_at: '',
        metadata: { agent_run_id: 'voice-run' } };
    const run: AgentTurnStarted = { run_id: 'voice-run', status: 'running', locale: 'it',
        events_url: '/agent-runs/voice-run/events', cancel_url: '/cancel', continue_url: '/continue', user_message: user };
    const answer: Message = { ...user, id: 11, role: 'assistant', content: 'Risposta verificata: cliente e ordini.' };
    vi.spyOn(chatApi, 'listMessages').mockResolvedValue([user, answer]);
    const plan: AgentRunEvent = {
        run_id: run.run_id, sequence: 1, type: 'research.planned', phase: 'research', locale: 'it',
        message_key: 'research.planned', message_params: {}, message: null, progress: null, can_cancel: true, created_at: null,
        data: { tasks: [{ id: 0, question: 'Chi è il cliente?' }, { id: 1, question: 'Quali sono gli ordini?' }] },
    };
    const sse = (event: AgentRunEvent) => new TextEncoder().encode(`id: ${event.sequence}\nevent: ${event.type}\ndata: ${JSON.stringify(event)}\n\n`);
    let events!: ReadableStreamDefaultController<Uint8Array>;
    let finishTool!: (response: Response) => void;
    const json = (body: unknown) => new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } });
    vi.stubGlobal('fetch', vi.fn(async (url: RequestInfo | URL) => {
        if (String(url).includes('/run?')) return json({ run });
        if (String(url).includes('/events')) return new Response(new ReadableStream<Uint8Array>({ start(controller) {
            events = controller;
            controller.enqueue(sse(plan));
            controller.enqueue(sse({ ...plan, sequence: 2, type: 'research.task',
                data: { research_flow_id: 0, task_status: 'documents' } }));
        } }), { headers: { 'Content-Type': 'text/event-stream' } });
        if (String(url).endsWith('/tools')) return new Promise<Response>((resolve) => { finishTool = resolve; });
        return json({ state });
    }));
    let driver!: FakeRealtimeDriver;
    const connect = FakeRealtimeDriver.prototype.connect;
    vi.spyOn(FakeRealtimeDriver.prototype, 'connect').mockImplementation(async function (this: FakeRealtimeDriver, descriptor) {
        driver = this;
        await connect.call(this, descriptor);
    });
    let voice!: UseRealtimeAgentResult;
    const initialMessages: Message[] = [];
    function VoiceChat() {
        const chat = useAgentChat({ conversationId: 7, filters: {}, initialMessages });
        voice = useRealtimeAgent({ conversationId: 7, filters: {}, availability: { available: true, reason: null },
            onRequireConversation: async () => 7, onAdoptRun: chat.adoptExternalRun,
            requestInFlight: chat.status === 'streaming' || chat.status === 'submitted' });
        return <MessageThread conversationId={7} messages={chat.messages} sdkStatus={chat.status}
            activeAgentRunId={chat.activeRun?.run_id} agentEvents={chat.events} onCancelAgent={chat.stop} />;
    }
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const view = render(<QueryClientProvider client={queryClient}><VoiceChat /></QueryClientProvider>);
    await act(async () => voice.start());
    act(() => driver.emit({ type: 'agent.tool.call', call: { id: 'voice-call', name: 'askmydocs.chat_turn', base_revision: 1,
        arguments: { question: user.content } } }));
    const tasks = await screen.findByTestId('agent-research-tasks');
    expect(screen.getByText(user.content)).toBeVisible();
    expect(tasks).toHaveTextContent('Chi è il cliente?');
    expect(tasks).toHaveTextContent('Quali sono gli ordini?');
    expect(within(tasks).getAllByRole('listitem')[0]).toHaveTextContent('Ricerca nei documenti');
    expect(screen.getByTestId('agent-activity-bar')).toHaveAttribute('aria-busy', 'true');
    expect(driver.toolResults).toHaveLength(0);
    expect(voice.active).toBe(true);
    await act(async () => {
        events.enqueue(sse({ ...plan, sequence: 3, type: 'run.completed', data: { response: { answer: answer.content } } }));
        events.close();
        finishTool(json({ result: { call_id: 'voice-call', status: 'completed', state_revision: 2,
            output: { run, response: { answer: answer.content } } } }));
    });
    expect(await screen.findByText(answer.content)).toBeVisible();
    await waitFor(() => expect(driver.toolResults).toHaveLength(1));
    expect(voice.active).toBe(true);
    await act(async () => voice.stop());
    view.unmount();
    queryClient.clear();
});
