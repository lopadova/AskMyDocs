import { useCallback, useEffect, useRef, useState } from 'react';
import {
    ElevenLabsRealtimeDriver,
    FakeRealtimeDriver,
    LaravelControlTransport,
    OpenAILiveDriver,
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
    const sessionConversationRef = useRef<number | null>(null);
    const onAdoptRunRef = useRef(onAdoptRun);
    onAdoptRunRef.current = onAdoptRun;

    const release = useCallback(async (finish: boolean): Promise<void> => {
        const client = clientRef.current;
        clientRef.current = null;
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

    const handleToolResult = useCallback(async (result: ToolResult): Promise<void> => {
        const output = result.output;
        const run = parseAgentRun(output?.run);
        if (run) {
            // The provider response must not be lost if the visual stream refresh
            // fails; adoptExternalRun already surfaces that error in the chat.
            await onAdoptRunRef.current(run).catch(() => undefined);
        }

        if (isHandoff(output?.handoff)) {
            setStatus('paused');
            await clientRef.current?.switchToText().catch(() => undefined);
        }
    }, []);

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
            );
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
            const provider = descriptor.provider === 'fake'
                ? new FakeRealtimeDriver()
                : descriptor.provider === 'openai'
                    ? new OpenAILiveDriver(request)
                    : descriptor.provider === 'elevenlabs'
                        ? new ElevenLabsRealtimeDriver(request)
                        : null;
            if (provider === null) throw new Error(`Unsupported realtime provider: ${descriptor.provider}`);

            const client = new RealtimeAgentClient(surfaces, provider, control, {
                confirmation: () => false,
            });
            clientRef.current = client;
            sessionConversationRef.current = targetId;
            setActive(true);
            client.on((event) => handleClientEvent(event, setStatus, setError, setActive));
            await client.connect(descriptor as unknown as ConnectionDescriptor);
        } catch (reason) {
            await release(false);
            const next = reason instanceof Error ? reason : new Error(String(reason));
            setError(next);
            setStatus('error');
            throw next;
        }
    }, [availability, conversationId, filters, handleToolResult, liveSources, onRequireConversation, release, status]);

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

class ObservingControlTransport implements ControlTransport {
    constructor(
        private readonly inner: ControlTransport,
        private readonly onToolResult: (result: ToolResult) => Promise<void>,
    ) {}

    async executeTool(input: ToolCallInput): Promise<ToolResult> {
        const result = await this.inner.executeTool(input);
        await this.onToolResult(result);

        return result;
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
