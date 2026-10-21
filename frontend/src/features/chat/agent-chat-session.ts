import { createStore } from 'zustand/vanilla';
import type { UseChatHelpers } from '@ai-sdk/react';
import type { UIMessage } from 'ai';
import { consumeAgentRun, type AgentRunEvent, type AgentRunStreamResult } from '../../lib/agent-run-events';
import { useTeamStore } from '../../lib/team-store';
import { chatApi, isFilterStateEmpty, type AgentTurnStarted, type AgentSelectionRequest, type FilterState, type LiveSourceSelection, type Message } from './chat.api';

export interface AgentConfirmation {
    physicalExtension: number;
    logicalExtension: number;
}

export interface AgentMessageOptions {
    mcpAppId?: string;
    selection?: AgentSelectionRequest;
}

interface TurnOptions {
    filters: FilterState;
    liveSources?: LiveSourceSelection;
    depth?: number;
    onFinish?: () => void;
    onError?: (error: Error) => void;
}

export interface AgentChatState {
    messages: Message[];
    status: UseChatHelpers<UIMessage>['status'];
    error: Error | null;
    events: AgentRunEvent[];
    activeRun: AgentTurnStarted | null;
    confirmation: AgentConfirmation | null;
    unreadAt: number | null;
}

/** A conversation owns its request. React views only subscribe and detach. */
export class AgentChatSession {
    readonly store = createStore<AgentChatState>(() => ({
        messages: [], status: 'ready', error: null, events: [],
        activeRun: null, confirmation: null, unreadAt: null,
    }));
    private controller: AbortController | null = null;
    private generation = 0;
    private inFlight = false;
    private refreshing = false;
    private sequence = 0;
    private viewers = 0;
    private disposed = false;

    constructor(readonly conversationId: number | null, private readonly tenantId: string | null) {}

    attach = (): (() => void) => {
        this.viewers++;
        this.store.setState({ unreadAt: null });
        return () => { this.viewers = Math.max(0, this.viewers - 1); };
    };

    hydrate = (messages: Message[]): void => {
        if (this.inFlight || this.refreshing) return;
        // Reopening can still supply the query cache's pre-run snapshot.
        const last = this.store.getState().messages.at(-1)?.id ?? 0;
        if ((messages.at(-1)?.id ?? 0) < last) return;
        this.store.setState({ messages });
    };

    setMessages = (next: Message[] | ((current: Message[]) => Message[])): void => {
        this.store.setState((state) => ({ messages: typeof next === 'function' ? next(state.messages) : next }));
    };

    private begin(): { controller: AbortController; generation: number } {
        if (this.conversationId === null) throw new Error('A conversation is required.');
        if (this.disposed) throw new Error('The chat session has ended.');
        if (this.inFlight) throw new Error('Un attimo, una cosa alla volta. La richiesta precedente è ancora in corso.');
        this.controller?.abort();
        this.controller = new AbortController();
        this.inFlight = true;
        this.refreshing = false;
        this.sequence = 0;
        this.store.setState({ error: null, events: [], confirmation: null, activeRun: null, status: 'submitted', unreadAt: null });
        return { controller: this.controller, generation: ++this.generation };
    }

    sendMessage = async ({ text }: { text: string }, options: TurnOptions, messageOptions?: AgentMessageOptions): Promise<void> => {
        const { controller, generation } = this.begin();
        try {
            const args = [this.conversationId!, text,
                isFilterStateEmpty(options.filters) ? undefined : options.filters,
                messageOptions?.mcpAppId, messageOptions?.selection, options.liveSources, options.depth] as const;
            const run = await (this.tenantId === null
                ? chatApi.startAgentTurn(...args)
                : chatApi.startAgentTurn(...args, this.tenantId));
            if (generation !== this.generation || controller.signal.aborted) {
                // Stop can precede the POST response containing the run id.
                if (!this.disposed) void this.cancel(run);
                return;
            }
            this.adopt(run);
            await this.consume(run, controller, generation, options);
        } catch (reason) {
            this.reportError(reason, generation, options);
            throw reason;
        }
    };

    adoptExternalRun = async (run: AgentTurnStarted, options: TurnOptions): Promise<void> => {
        if (this.store.getState().activeRun?.run_id === run.run_id && this.inFlight) return;
        if (this.inFlight) throw new Error('A request is already in progress.');
        const { controller, generation } = this.begin();
        this.adopt(run);
        try {
            await this.consume(run, controller, generation, options);
        } catch (reason) {
            this.reportError(reason, generation, options);
            throw reason;
        }
    };

    private adopt(run: AgentTurnStarted): void {
        this.store.setState((state) => ({
            activeRun: run, status: 'streaming',
            messages: state.messages.some((message) => message.id === run.user_message.id)
                ? state.messages : [...state.messages, run.user_message],
        }));
    }

    private recordEvent = (event: AgentRunEvent): void => {
        this.sequence = Math.max(this.sequence, event.sequence);
        this.store.setState((state) => {
            if (state.events.some((item) => item.sequence === event.sequence)) return state;
            const next = [...state.events, event];
            const details = new Set(next.filter((item) => !item.type.startsWith('research.')).slice(-50).map((item) => item.sequence));
            return { events: next.filter((item) => item.type.startsWith('research.') || details.has(item.sequence)) };
        });
    };

    private async consume(run: AgentTurnStarted, controller: AbortController, generation: number, options: TurnOptions): Promise<void> {
        const result = await consumeAgentRun({
            initialSequence: this.sequence,
            signal: controller.signal,
            open: (after, signal) => this.fetchEvents(run.events_url, after, signal),
            onEvent: this.recordEvent,
        });
        await this.settle(result, generation, options);
    }

    private async settle(result: AgentRunStreamResult, generation: number, options: TurnOptions): Promise<void> {
        if (generation !== this.generation) return;
        if (result.reason === 'awaiting_confirmation') {
            const raw = result.event.data.extension;
            const extension = typeof raw === 'object' && raw !== null ? raw as Record<string, unknown> : {};
            this.store.setState({ status: 'ready', confirmation: {
                physicalExtension: Math.max(1, Number(extension.physical_extension ?? 25)),
                logicalExtension: Math.max(0, Number(extension.logical_extension ?? 0)),
            } });
            return;
        }
        if (result.reason === 'failed') throw new Error(result.event.message ?? 'The agent could not complete the search.');
        this.inFlight = false;
        this.refreshing = result.reason !== 'cancelled';
        const timestamp = Date.parse(result.event.created_at ?? '');
        const answeredAt = Number.isFinite(timestamp) ? timestamp : Date.now();
        this.store.setState({
            status: 'ready', activeRun: null, confirmation: null,
            unreadAt: result.reason !== 'cancelled' && this.viewers === 0
                ? answeredAt : null,
        });
        if (result.reason !== 'cancelled' && this.conversationId !== null) {
            const messages = await (this.tenantId === null
                ? chatApi.listMessages(this.conversationId)
                : chatApi.listMessages(this.conversationId, this.tenantId));
            if (generation !== this.generation) return;
            this.refreshing = false;
            // The user may have left after the terminal event but before
            // history delivered the answer to the screen.
            this.store.setState({ messages, unreadAt: this.viewers === 0 ? answeredAt : null });
        }
        options.onFinish?.();
    }

    private reportError(reason: unknown, generation: number, options: TurnOptions): void {
        if (generation !== this.generation || (reason instanceof DOMException && reason.name === 'AbortError')) return;
        this.inFlight = false;
        this.refreshing = false;
        const error = reason instanceof Error ? reason : new Error(String(reason));
        this.store.setState({ error, status: 'error' });
        options.onError?.(error);
    }

    stop = (): void => {
        const run = this.store.getState().activeRun;
        ++this.generation;
        this.controller?.abort();
        this.inFlight = false;
        this.refreshing = false;
        this.store.setState({ status: 'ready', activeRun: null, confirmation: null });
        if (run) void this.cancel(run);
    };

    private cancel(run: AgentTurnStarted): Promise<void> {
        return (this.tenantId === null ? chatApi.cancelAgentRun(run.cancel_url)
            : chatApi.cancelAgentRun(run.cancel_url, this.tenantId)).catch(() => undefined);
    }

    continueRun = async (options: TurnOptions): Promise<void> => {
        const { activeRun: run, confirmation, status } = this.store.getState();
        if (!run || !confirmation || status === 'submitted' || status === 'streaming') return;
        const generation = ++this.generation;
        const controller = new AbortController();
        this.controller = controller;
        this.inFlight = true;
        this.store.setState({ error: null, status: 'submitted' });
        try {
            const args = [run.continue_url, confirmation.physicalExtension, confirmation.logicalExtension] as const;
            await (this.tenantId === null ? chatApi.continueAgentRun(...args) : chatApi.continueAgentRun(...args, this.tenantId));
            if (generation !== this.generation) return;
            this.store.setState({ status: 'streaming', confirmation: null });
            await this.consume(run, controller, generation, options);
        } catch (reason) {
            this.reportError(reason, generation, options);
            throw reason;
        }
    };

    dispose(): void {
        this.disposed = true;
        ++this.generation;
        this.controller?.abort();
    }

    private fetchEvents(url: string, after: number, signal: AbortSignal): Promise<Response> {
        const headers: Record<string, string> = {
            Accept: 'text/event-stream, application/json',
            'X-Requested-With': 'XMLHttpRequest', 'Last-Event-ID': String(after),
        };
        if (this.tenantId !== null) headers['X-Tenant-Id'] = this.tenantId;
        return fetch(`${url}${url.includes('?') ? '&' : '?'}after=${after}`, {
            method: 'GET', credentials: 'same-origin', cache: 'no-store', headers, signal,
        });
    }
}

const sessions = new Map<string, AgentChatSession>();

export function getAgentChatSession(conversationId: number | null, userId: number | null, tenantId: string | null): AgentChatSession {
    const key = JSON.stringify([userId, tenantId, conversationId]);
    let session = sessions.get(key);
    if (!session) {
        session = new AgentChatSession(conversationId, tenantId);
        sessions.set(key, session);
    }
    return session;
}

export function clearAgentChatSessions(): void {
    for (const session of sessions.values()) session.dispose();
    sessions.clear();
}

// Logout discards transcripts. Team switches keep runs with their own tenant.
useTeamStore.subscribe((state, previous) => {
    if (state.userId !== previous.userId) clearAgentChatSessions();
});
