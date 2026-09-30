import { useCallback, useEffect, useRef, useState } from 'react';
import {
    ElevenLabsRealtimeDriver,
    FakeRealtimeDriver,
    LaravelControlTransport,
    RealtimeAgentClient,
    SurfaceRegistry,
    type AgentState,
    type ConnectionDescriptor,
    type ConversationMessage,
    type ConversationMessageInput,
    type ControlTransport,
    type JsonObject,
    type ProviderUsageInput,
    type RealtimeAgentClientEvent,
    type SessionAudit,
    type SurfaceSnapshot,
    type ToolCallInput,
    type ToolResult,
    type UiCommand,
    type UiCommandResult,
    type UsageRecord,
} from '@agents-full-duplex/realtime-agent-client';
import { useTeamStore } from '../../lib/team-store';
import {
    chatApi,
    countSelectedFilters,
    type AgentTurnStarted,
    type FilterState,
    type LiveSourceSelection,
} from './chat.api';
import { createAskMyDocsOpenAILiveDriver } from './openai-live-driver';

export type RealtimeAgentStatus =
    | 'idle'
    | 'connecting'
    | 'listening'
    | 'processing'
    | 'speaking'
    | 'paused'
    | 'error';

export interface RealtimeAgentFeatureStatus {
    available: boolean;
    reason: string | null;
}

interface UseRealtimeAgentOptions {
    conversationId: number | null;
    filters: FilterState;
    liveSources?: LiveSourceSelection;
    availability?: RealtimeAgentFeatureStatus;
    onRequireConversation: () => Promise<number | null>;
    onAdoptRun: (run: AgentTurnStarted) => Promise<void>;
    requestInFlight?: boolean;
}

export interface UseRealtimeAgentResult {
    status: RealtimeAgentStatus;
    error: Error | null;
    active: boolean;
    start: () => Promise<void>;
    stop: () => Promise<void>;
}

/** Owns one Agents Bridge browser session for the current chat conversation. */
export function useRealtimeAgent(options: UseRealtimeAgentOptions): UseRealtimeAgentResult {
    const {
        conversationId,
        filters,
        liveSources,
        availability,
        onRequireConversation,
        onAdoptRun,
    } = options;
    const [status, setStatus] = useState<RealtimeAgentStatus>('idle');
    const [error, setError] = useState<Error | null>(null);
    const [active, setActive] = useState(false);
    const clientRef = useRef<RealtimeAgentClient | null>(null);
    const controlRef = useRef<ObservingControlTransport | null>(null);
    const pendingRef = useRef(false);
    const adoptedRunsRef = useRef(new Set<string>());
    const requestInFlightRef = useRef(options.requestInFlight ?? false);
    requestInFlightRef.current = options.requestInFlight ?? false;
    const sessionConversationRef = useRef<number | null>(null);
    const onAdoptRunRef = useRef(onAdoptRun);
    onAdoptRunRef.current = onAdoptRun;

    const release = useCallback(async (finish: boolean): Promise<void> => {
        const client = clientRef.current;
        clientRef.current = null;
        controlRef.current?.dispose();
        controlRef.current = null;
        pendingRef.current = false;
        adoptedRunsRef.current.clear();
        sessionConversationRef.current = null;
        setActive(false);
        if (!client) return;

        if (finish) await client.finish().catch(() => undefined);
        await client.disconnect().catch(() => undefined);
    }, []);

    const stop = useCallback(async (): Promise<void> => {
        await release(true);
        setError(null);
        setStatus('idle');
    }, [release]);

    const adoptRun = useCallback((run: AgentTurnStarted): void => {
        if (adoptedRunsRef.current.has(run.run_id)) return;
        adoptedRunsRef.current.add(run.run_id);
        // Observe immediately, but do not make the spoken answer wait for UI/SSE recovery.
        void onAdoptRunRef.current(run).catch(() => {
            // Allow the final receipt to recover a failed early UI subscription.
            adoptedRunsRef.current.delete(run.run_id);
        });
    }, []);

    const handleToolResult = useCallback(async (result: ToolResult): Promise<void> => {
        const output = result.output;
        const run = parseAgentRun(output?.run);
        if (run) {
            // The provider response must not be lost if the visual stream refresh
            // fails; adoptExternalRun already surfaces that error in the chat.
            adoptRun(run);
        }

        if (isHandoff(output?.handoff)) {
            setStatus('paused');
            await clientRef.current?.switchToText().catch(() => undefined);
        }
    }, [adoptRun]);

    const start = useCallback(async (): Promise<void> => {
        if (availability?.available !== true) {
            throw new Error(unavailableMessage(availability?.reason));
        }
        if (status !== 'idle' && status !== 'error') return;

        setError(null);
        setStatus('connecting');
        try {
            const targetId = conversationId ?? await onRequireConversation();
            if (targetId === null) throw new Error('Could not start a conversation for Live assistant.');

            await release(true);
            const descriptor = await chatApi.startRealtimeAgent(targetId, filters, liveSources);
            const request = tenantAwareFetch;
            const control = new ObservingControlTransport(
                new LaravelControlTransport(descriptor.session_id, '/realtime-agent', request),
                handleToolResult,
                {
                    receiptUrl: `/conversations/${targetId}/realtime-agent/${encodeURIComponent(descriptor.session_id)}/run`,
                    request,
                    onRun: adoptRun,
                    onPending: (pending) => {
                        pendingRef.current = pending;
                        setStatus((current) => pending ? 'processing' : current === 'processing' ? 'listening' : current);
                    },
                    isBusy: () => requestInFlightRef.current,
                    busyMessage: descriptor.busy_message ?? 'Un attimo, una cosa alla volta. Sto ancora completando la richiesta precedente.',
                },
            );
            controlRef.current = control;
            const surfaces = new SurfaceRegistry();
            surfaces.register({
                id: 'chat.conversation',
                snapshot: () => ({
                    title: 'AskMyDocs conversation',
                    components: {
                        context: {
                            type: 'chat-context',
                            label: 'Current chat scope',
                            state: {
                                conversation_bound: true,
                                filters_selected: countSelectedFilters(filters),
                                live_api_sources: liveSources?.api.length ?? 0,
                                live_mcp_sources: liveSources?.mcp.length ?? 0,
                            },
                            actions: [],
                        },
                    },
                }),
                actions: {},
            });
            let provider;
            try {
                provider = descriptor.provider === 'fake'
                    ? new FakeRealtimeDriver()
                    : descriptor.provider === 'openai'
                        ? await createAskMyDocsOpenAILiveDriver(request)
                        : descriptor.provider === 'elevenlabs'
                            ? new ElevenLabsRealtimeDriver(request)
                            : null;
            } catch (reason) {
                // Audio is prepared before the client exists, so finish the
                // server session explicitly when permission/device setup fails.
                await control.finish(Number(descriptor.state.session.revision)).catch(() => undefined);
                throw reason;
            }
            if (provider === null) throw new Error(`Unsupported realtime provider: ${descriptor.provider}`);

            const client = new RealtimeAgentClient(surfaces, provider, control, {
                confirmation: () => false,
            });
            clientRef.current = client;
            sessionConversationRef.current = targetId;
            setActive(true);
            client.on((event) => {
                if (clientRef.current !== client) return; // Ignore callbacks from an ended/previous session.
                handleClientEvent(event, (next) => setStatus(pendingRef.current && next === 'listening' ? 'processing' : next), setError, setActive);
            });
            await client.connect(descriptor as unknown as ConnectionDescriptor);
        } catch (reason) {
            // The server session already exists by the time a browser/provider
            // connection can fail (for example when microphone access is
            // denied). Finish it immediately so retries do not accumulate
            // active sessions until the TTL expires.
            await release(true);
            const next = realtimeAgentConnectionError(reason);
            setError(next);
            setStatus('error');
            throw next;
        }
    }, [adoptRun, availability, conversationId, filters, handleToolResult, liveSources, onRequireConversation, release, status]);

    useEffect(() => {
        const sessionConversation = sessionConversationRef.current;
        if (sessionConversation !== null && sessionConversation !== conversationId) {
            void stop();
        }
    }, [conversationId, stop]);

    useEffect(() => () => {
        void release(true);
    }, [release]);

    return {
        status,
        error,
        active,
        start,
        stop,
    };
}

/** Translate browser media failures into instructions a user can act on. */
export function realtimeAgentConnectionError(reason: unknown): Error {
    const error = reason instanceof Error ? reason : new Error(String(reason));
    const name = reason !== null && typeof reason === 'object' && 'name' in reason
        ? String(reason.name)
        : error.name;

    if (name === 'NotAllowedError' || name === 'SecurityError') {
        return new Error(
            'Microphone access was denied. Allow microphone access for this site in your browser settings, then try again.',
        );
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        return new Error('No microphone was found. Connect or enable a microphone, then try again.');
    }
    if (name === 'NotReadableError' || name === 'TrackStartError') {
        return new Error('The microphone is unavailable or already in use by another application.');
    }

    return error;
}

interface LiveTurnObservation {
    receiptUrl: string;
    request: typeof fetch;
    onRun: (run: AgentTurnStarted) => void;
    onPending: (pending: boolean) => void;
    isBusy: () => boolean;
    busyMessage: string;
}

class ObservingControlTransport implements ControlTransport {
    private pending = false;
    private disposed = false;
    private observation: AbortController | null = null;

    constructor(
        private readonly inner: ControlTransport,
        private readonly onToolResult: (result: ToolResult) => Promise<void>,
        private readonly live?: LiveTurnObservation,
    ) {}

    async executeTool(input: ToolCallInput): Promise<ToolResult> {
        if (this.disposed) throw new DOMException('The voice session ended.', 'AbortError');
        const chatTurn = input.name === 'askmydocs.chat_turn';
        if (chatTurn && (this.pending || this.live?.isBusy())) {
            // Return a spoken control notice, never queue or replace the pending business request.
            return { call_id: input.id, status: 'completed', state_revision: input.base_revision,
                output: { busy: true, retry: false, response: { answer: this.live?.busyMessage ?? 'One moment, one thing at a time.' } } };
        }
        if (chatTurn) {
            this.pending = true;
            this.live?.onPending(true);
            this.observation = new AbortController();
            void this.observeRun(input.id, this.observation.signal);
        }
        try {
            const result = await this.inner.executeTool(input);
            if (this.disposed) throw new DOMException('The voice session ended.', 'AbortError');
            await this.onToolResult(result);
            return result;
        } finally {
            if (chatTurn) {
                this.observation?.abort();
                this.observation = null;
                this.pending = false;
                if (!this.disposed) this.live?.onPending(false);
            }
        }
    }

    dispose(): void {
        this.disposed = true;
        this.observation?.abort();
        this.observation = null;
    }

    private async observeRun(callId: string, signal: AbortSignal): Promise<void> {
        if (!this.live) return;
        while (!signal.aborted) {
            try {
                const response = await this.live.request(`${this.live.receiptUrl}?call_id=${encodeURIComponent(callId)}`, {
                    headers: { Accept: 'application/json' }, cache: 'no-store', signal,
                });
                if ([401, 403, 404, 410].includes(response.status)) return;
                if (response.ok) {
                    const body = await response.json() as { run?: unknown };
                    const run = parseAgentRun(body.run);
                    if (run && !signal.aborted && !this.disposed) {
                        this.live.onRun(run);
                        return;
                    }
                }
            } catch {
                // Visual observation is best effort; the canonical tool still owns its answer.
            }
            if (signal.aborted) return;
            await new Promise<void>((resolve) => {
                const finish = () => { clearTimeout(timer); signal.removeEventListener('abort', finish); resolve(); };
                const timer = setTimeout(finish, 750);
                signal.addEventListener('abort', finish, { once: true });
            });
        }
    }

    refreshState(): Promise<AgentState> { return this.inner.refreshState(); }
    syncSurface(baseRevision: number, surface: SurfaceSnapshot): Promise<AgentState> {
        return this.inner.syncSurface(baseRevision, surface);
    }
    resolveConfirmation(id: string, accepted: boolean): Promise<ToolResult> {
        return this.inner.resolveConfirmation(id, accepted);
    }
    completeUiCommand(command: UiCommand, baseRevision: number, result: UiCommandResult): Promise<AgentState> {
        return this.inner.completeUiCommand(command, baseRevision, result);
    }
    finish(baseRevision: number): Promise<AgentState> { return this.inner.finish(baseRevision); }
    recordMessage(input: ConversationMessageInput): Promise<ConversationMessage> {
        return this.inner.recordMessage(input);
    }
    recordUsage(input: ProviderUsageInput): Promise<UsageRecord> { return this.inner.recordUsage(input); }
    fetchAudit(): Promise<SessionAudit> { return this.inner.fetchAudit(); }
}

async function tenantAwareFetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response> {
    const headers = new Headers(init?.headers);
    headers.set('X-Requested-With', 'XMLHttpRequest');
    const team = useTeamStore.getState().currentTeam;
    if (team !== null) headers.set('X-Tenant-Id', team);

    return fetch(input, { ...init, credentials: 'same-origin', headers });
}

function handleClientEvent(
    event: RealtimeAgentClientEvent,
    setStatus: (status: RealtimeAgentStatus) => void,
    setError: (error: Error | null) => void,
    setActive: (active: boolean) => void,
): void {
    if (event.type === 'agent.connected') {
        setActive(true);
        setStatus('listening');
    }
    if (event.type === 'agent.disconnected') {
        setActive(false);
        setStatus('idle');
    }
    if (event.type === 'agent.tool.call') setStatus('processing');
    if (event.type === 'agent.transcript.delta') {
        setStatus(event.role === 'agent' ? 'speaking' : 'listening');
    }
    if (event.type === 'agent.transcript.final') setStatus('listening');
    if (event.type === 'agent.mode.changed') setStatus(event.mode === 'text' ? 'paused' : 'listening');
    if (event.type === 'agent.error') {
        setError(event.error);
        setStatus('error');
    }
}

function parseAgentRun(value: unknown): AgentTurnStarted | null {
    if (value === null || typeof value !== 'object') return null;
    const run = value as Partial<AgentTurnStarted>;
    if (typeof run.run_id !== 'string'
        || typeof run.events_url !== 'string'
        || typeof run.cancel_url !== 'string'
        || typeof run.continue_url !== 'string'
        || run.user_message === null
        || typeof run.user_message !== 'object') return null;

    return run as AgentTurnStarted;
}

function isHandoff(value: unknown): boolean {
    return value !== null
        && typeof value === 'object'
        && (value as JsonObject).required === true;
}

export function unavailableMessage(reason?: string | null): string {
    if (reason === 'missing_credentials') return 'Live assistant requires provider credentials.';
    if (reason === 'disabled') return 'Live assistant is disabled for this environment.';
    if (reason === 'provider_unavailable') return 'The configured Live assistant provider is unavailable.';

    return 'Live assistant is not available.';
}
