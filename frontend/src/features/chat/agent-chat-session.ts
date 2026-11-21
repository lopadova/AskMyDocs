import { createStore } from 'zustand/vanilla';
import type { UseChatHelpers } from '@ai-sdk/react';
import type { UIMessage } from 'ai';
import { consumeAgentRun, type AgentRunEvent, type AgentRunStreamResult } from '../../lib/agent-run-events';
import { waitForAgentUpdate } from '../../lib/agent-realtime';
import { useTeamStore } from '../../lib/team-store';
import { chatApi, isFilterStateEmpty, type AgentRunReference, type AgentTurnStarted, type AgentSelectionRequest, type ConversationActivity, type FilterState, type LiveSourceSelection, type Message } from './chat.api';

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

type SessionRun = AgentRunReference & { confirmation: AgentConfirmation | null };

interface SessionRunWatch {
    run: SessionRun;
    sequence: number;
    controller?: AbortController;
    onFinish?: () => void;
    onError?: (error: Error) => void;
}

const terminalStatuses = ['completed', 'partial', 'failed', 'cancelled'];

export interface AgentChatState {
    messages: Message[];
    status: UseChatHelpers<UIMessage>['status'];
    error: Error | null;
    events: AgentRunEvent[];
    activeRun: AgentRunReference | null;
    confirmation: AgentConfirmation | null;
    unreadAt: number | null;
    concurrentSession: boolean;
    sessionRuns: SessionRun[];
}

/** A conversation owns its request. React views only subscribe and detach. */
export class AgentChatSession {
    readonly store = createStore<AgentChatState>(() => ({
        messages: [], status: 'ready', error: null, events: [],
        activeRun: null, confirmation: null, unreadAt: null, concurrentSession: false, sessionRuns: [],
    }));
    private controller: AbortController | null = null;
    private generation = 0;
    private inFlight = false;
    private refreshing = false;
    private sequence = 0;
    private viewers = 0;
    private disposed = false;
    private latestReplyId = 0;
    private readThrough = 0;
    private requestedReadThrough = 0;
    private acknowledging = false;
    private recoveredRunId: string | null = null;
    private readonly sessionWatches = new Map<string, SessionRunWatch>();
    private readonly settledSessionRuns = new Set<string>();

    constructor(readonly conversationId: number | null, private readonly tenantId: string | null) {}

    attach = (): (() => void) => {
        this.viewers++;
        this.store.setState({ unreadAt: null });
        this.readDisplayedReply();
        this.resumeVisible();
        return () => {
            this.viewers = Math.max(0, this.viewers - 1);
            if (this.viewers === 0) this.suspend();
        };
    };

    hydrate = (messages: Message[]): void => {
        // Reopening can still supply the query cache's pre-run snapshot.
        // A recovered run also needs its canonical history while streaming.
        const last = this.store.getState().messages.at(-1)?.id ?? 0;
        if ((messages.at(-1)?.id ?? 0) < last) return;
        this.store.setState({ messages });
        this.readDisplayedReply();
    };

    private readDisplayedReply(): void {
        const reply = [...this.store.getState().messages].reverse().find((message) => message.role === 'assistant');
        if (reply) this.latestReplyId = Math.max(this.latestReplyId, reply.id);
        if (this.viewers > 0) {
            const displayedId = this.store.getState().concurrentSession ? reply?.id ?? 0 : this.latestReplyId;
            this.requestedReadThrough = Math.max(this.requestedReadThrough, displayedId);
            this.store.setState({ unreadAt: null });
        } else if (this.latestReplyId > 0 && this.latestReplyId <= Math.max(this.readThrough, this.requestedReadThrough)) {
            this.store.setState({ unreadAt: null });
        }
        void this.acknowledgeRead();
    }

    private async acknowledgeRead(): Promise<void> {
        if (this.disposed || this.acknowledging || this.conversationId === null || this.tenantId === null
            || this.requestedReadThrough <= this.readThrough) return;
        const messageId = this.requestedReadThrough;
        this.acknowledging = true;
        try {
            const receipt = await chatApi.markConversationRead(this.conversationId, messageId, this.tenantId);
            if (this.disposed) return;
            this.readThrough = Math.max(this.readThrough, receipt.last_read_message_id);
        } catch {
            // The next activity reconciliation retries the exact receipt,
            // including when the user has already left this conversation.
            return;
        } finally {
            this.acknowledging = false;
        }
        if (this.requestedReadThrough > this.readThrough) void this.acknowledgeRead();
    }

    /** Reconcile server truth after reload, focus, or another device's read. */
    restore = (activity: ConversationActivity, onFinish?: () => void): void => {
        if (this.disposed) return;
        this.readThrough = Math.max(this.readThrough, activity.last_read_message_id);
        if (activity.latest_reply && activity.latest_reply.message_id >= this.latestReplyId) {
            this.latestReplyId = Math.max(this.latestReplyId, activity.latest_reply.message_id);
            const unread = activity.latest_reply.message_id > Math.max(this.readThrough, this.requestedReadThrough);
            if (unread && this.viewers === 0) {
                const at = Date.parse(activity.latest_reply.created_at);
                this.store.setState({ unreadAt: Number.isFinite(at) ? at : Date.now() });
            } else if (!unread) {
                this.store.setState({ unreadAt: null });
            }
        }
        if (this.viewers > 0) this.readDisplayedReply();
        else void this.acknowledgeRead();
        if (activity.runs || activity.run?.concurrent_session) {
            this.store.setState({ concurrentSession: true });
            for (const run of activity.runs ?? (activity.run ? [activity.run] : [])) {
                if (!terminalStatuses.includes(run.status)) this.trackSessionRun(run, { onFinish });
            }
            this.syncSessionRuns();
            const last = [...this.store.getState().messages].reverse().find((message) => message.role === 'assistant')?.id ?? 0;
            if (this.viewers > 0 && this.latestReplyId > last) void this.refreshSessionHistory(onFinish);
            return;
        }
        const waitingForConfirmation = this.store.getState().confirmation !== null && this.store.getState().status === 'ready';
        if ((this.inFlight && !waitingForConfirmation) || this.refreshing) return;
        const run = activity.run;
        if (!run) return;
        const terminal = ['completed', 'partial', 'failed', 'cancelled'].includes(run.status);
        if (terminal) {
            this.inFlight = false;
            this.store.setState({ status: run.status === 'failed' ? 'error' : 'ready', activeRun: null, confirmation: null });
            // Only an open view needs a transcript; badges never load it.
            const last = [...this.store.getState().messages].reverse().find((message) => message.role === 'assistant')?.id ?? 0;
            if (this.viewers > 0 && this.latestReplyId > last) void this.refreshRecoveredHistory(onFinish);
            return;
        }
        if (this.store.getState().events.some((event) => event.run_id === run.run_id
            && ['run.completed', 'run.partial', 'run.failed', 'run.cancelled'].includes(event.type))) return;
        if (run.status === 'awaiting_confirmation') {
            this.inFlight = true;
            this.store.setState({ status: 'ready', activeRun: run, confirmation: run.confirmation });
            return;
        }
        this.store.setState({ status: 'streaming', activeRun: run, confirmation: null, error: null });
        if (this.viewers === 0 || document.visibilityState === 'hidden') return;
        this.watch(run, onFinish);
    };

    private watch(run: AgentRunReference, onFinish?: () => void): void {
        // Replay the existing durable event log; never repeat the turn POST.
        const sameRun = this.recoveredRunId === run.run_id;
        this.recoveredRunId = run.run_id;
        if (!sameRun) {
            this.sequence = 0;
            this.store.setState({ events: [] });
        }
        const controller = new AbortController();
        this.controller?.abort();
        this.controller = controller;
        const generation = ++this.generation;
        this.inFlight = true;
        this.store.setState({ status: 'streaming', activeRun: run, confirmation: null, error: null });
        void this.consume(run, controller, generation, { filters: {}, onFinish }).catch((reason) => {
            this.reportError(reason, generation, { filters: {} });
        });
    };

    private async refreshRecoveredHistory(onFinish?: () => void): Promise<void> {
        if (this.refreshing || this.conversationId === null || this.tenantId === null) return;
        this.refreshing = true;
        const generation = this.generation;
        try {
            const messages = await chatApi.listMessages(this.conversationId, this.tenantId);
            if (this.disposed || generation !== this.generation) return;
            this.store.setState({ messages });
            this.readDisplayedReply();
            onFinish?.();
        } catch {
            // Reconciliation retries without resending the question.
        } finally {
            if (generation === this.generation) this.refreshing = false;
        }
    }

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
        this.store.setState({ error: null, events: [], confirmation: null, activeRun: null, status: 'submitted' });
        return { controller: this.controller, generation: ++this.generation };
    }

    sendMessage = async ({ text }: { text: string }, options: TurnOptions, messageOptions?: AgentMessageOptions): Promise<void> => {
        const args = [this.conversationId!, text,
            isFilterStateEmpty(options.filters) ? undefined : options.filters,
            messageOptions?.mcpAppId, messageOptions?.selection, options.liveSources, options.depth] as const;
        const post = () => this.tenantId === null
            ? chatApi.startAgentTurn(...args)
            : chatApi.startAgentTurn(...args, this.tenantId);
        if (this.store.getState().concurrentSession && !this.inFlight) {
            this.store.setState({ error: null });
            const run = await post();
            if (this.disposed) return;
            if (run.concurrent_session) {
                this.acceptSessionTurn(run, options);
            } else {
                this.store.setState({ concurrentSession: false });
                await this.adoptExternalRun(run, options);
            }
            return;
        }
        const { controller, generation } = this.begin();
        try {
            const run = await post();
            if (generation !== this.generation || controller.signal.aborted) {
                // Stop can precede the POST response containing the run id.
                if (!this.disposed) void this.cancel(run);
                return;
            }
            if (run.concurrent_session) {
                this.inFlight = false;
                this.acceptSessionTurn(run, options);
                return;
            }
            this.adopt(run);
            if (this.viewers === 0 || document.visibilityState === 'hidden') {
                this.inFlight = false;
                return;
            }
            await this.consume(run, controller, generation, options);
        } catch (reason) {
            this.reportError(reason, generation, options);
            throw reason;
        }
    };

    adoptExternalRun = async (run: AgentTurnStarted, options: TurnOptions): Promise<void> => {
        if (this.store.getState().activeRun?.run_id === run.run_id && this.inFlight) return;
        if (this.inFlight || this.sessionWatches.size > 0) throw new Error('A request is already in progress.');
        const { controller, generation } = this.begin();
        this.adopt(run);
        try {
            await this.consume(run, controller, generation, options);
        } catch (reason) {
            this.reportError(reason, generation, options);
            throw reason;
        }
    };

    private acceptSessionTurn(run: AgentTurnStarted, options: TurnOptions): void {
        this.store.setState((state) => ({
            concurrentSession: true,
            messages: state.messages.some((message) => message.id === run.user_message.id)
                ? state.messages : [...state.messages, run.user_message].sort((a, b) => a.id - b.id),
        }));
        this.trackSessionRun({ ...run, confirmation: null }, options);
        this.syncSessionRuns();
    }

    private trackSessionRun(run: SessionRun, callbacks: Pick<TurnOptions, 'onFinish' | 'onError'>): void {
        if (this.settledSessionRuns.has(run.run_id)) return;
        const watch = this.sessionWatches.get(run.run_id) ?? { run, sequence: 0, ...callbacks };
        if (!watch.controller && (watch.run.confirmation || run.confirmation)) watch.run = run;
        this.sessionWatches.set(run.run_id, watch);
        this.watchSessionRun(watch);
    }

    private syncSessionRuns(): void {
        const sessionRuns = [...this.sessionWatches.values()].map(({ run }) => run);
        const activeRun = sessionRuns.find((run) => !run.confirmation) ?? sessionRuns[0] ?? null;
        this.store.setState({
            sessionRuns, activeRun, confirmation: activeRun?.confirmation ?? null,
            status: activeRun && !activeRun.confirmation ? 'streaming' : 'ready',
        });
    }

    private watchSessionRun(watch: SessionRunWatch): void {
        if (watch.controller || this.disposed || this.viewers === 0 || document.visibilityState === 'hidden') return;
        const controller = new AbortController();
        watch.controller = controller;
        void consumeAgentRun({
            initialSequence: watch.sequence,
            signal: controller.signal,
            open: (after, signal) => this.fetchEvents(watch.run.events_url, after, signal),
            waitForUpdate: (signal) => waitForAgentUpdate(watch.run.run_id, signal),
            skipResumedConfirmations: true,
            onEvent: (event) => {
                watch.sequence = event.sequence;
                this.store.setState(({ events }) => {
                    const others = events.filter((item) => item.run_id !== event.run_id && this.sessionWatches.has(item.run_id));
                    const turnEvents = events.filter((item) => item.run_id === event.run_id).slice(-79);
                    return { events: [...others, ...turnEvents, event] };
                });
                if (event.type === 'research.queued' && event.data.interim_reply === true) void this.refreshSessionHistory();
            },
        }).then((result) => {
            if (controller.signal.aborted) return;
            if (result.reason === 'awaiting_confirmation') {
                watch.run = { ...watch.run, status: 'awaiting_confirmation', confirmation: watch.run.confirmation ?? confirmationFrom(result.event) };
                this.syncSessionRuns();
                return;
            }
            const runId = watch.run.run_id;
            this.settledSessionRuns.add(runId);
            this.sessionWatches.delete(runId);
            this.syncSessionRuns();
            if (result.reason === 'failed') {
                const error = new Error(result.event.message ?? 'The agent could not complete the search.');
                this.store.setState({ error });
                watch.onError?.(error);
            }
            void this.refreshSessionHistory(watch.onFinish);
        }).catch(() => {
            // The durable run is recovered by the next activity snapshot.
        }).finally(() => {
            if (watch.controller === controller) delete watch.controller;
        });
    }

    private async refreshSessionHistory(onFinish?: () => void): Promise<void> {
        if (this.disposed || this.conversationId === null || this.viewers === 0) return;
        try {
            const messages = await (this.tenantId === null
                ? chatApi.listMessages(this.conversationId)
                : chatApi.listMessages(this.conversationId, this.tenantId));
            if (this.disposed) return;
            // A concurrent POST can materialize a newer question while this
            // history request is returning an earlier reply.
            this.setMessages((current) => [...new Map([...current, ...messages].map((message) => [message.id, message])).values()]
                .sort((a, b) => a.id - b.id));
            this.readDisplayedReply();
            onFinish?.();
        } catch {
            // A later activity snapshot retries the transcript request.
        }
    }

    private adopt(run: AgentTurnStarted): void {
        this.recoveredRunId = run.run_id;
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
            const events = next.filter((item) => item.type.startsWith('research.') || details.has(item.sequence));
            // Keep interrupted findings attached to their turn when a new turn
            // clears the live event buffer. The server log is durable on reload.
            if (events.some((item) => item.type === 'research.findings')
                && (event.type === 'research.findings' || event.type.startsWith('run.'))) {
                return { events, messages: state.messages.map((message) => message.role === 'user'
                    && message.metadata?.agent_run_id === event.run_id
                    ? { ...message, metadata: { ...message.metadata, agent_activity: events } } : message) };
            }
            return { events };
        });
    };

    private async consume(run: AgentRunReference, controller: AbortController, generation: number, options: TurnOptions): Promise<void> {
        const result = await consumeAgentRun({
            initialSequence: this.sequence,
            signal: controller.signal,
            open: (after, signal) => this.fetchEvents(run.events_url, after, signal),
            onEvent: (event) => { if (!this.disposed && generation === this.generation) this.recordEvent(event); },
            waitForUpdate: (signal) => waitForAgentUpdate(run.run_id, signal),
        });
        await this.settle(result, generation, options);
    }

    private async settle(result: AgentRunStreamResult, generation: number, options: TurnOptions): Promise<void> {
        if (generation !== this.generation) return;
        if (result.reason === 'awaiting_confirmation') {
            this.store.setState({ status: 'ready', confirmation: confirmationFrom(result.event) });
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
            this.readDisplayedReply();
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

    stop = (runId?: string): void => {
        const run = this.store.getState().activeRun;
        const watch = this.sessionWatches.get(runId ?? run?.run_id ?? '');
        if (watch) {
            void this.cancel(watch.run).then(() => this.watchSessionRun(watch));
            return;
        }
        if (runId) return;
        ++this.generation;
        this.controller?.abort();
        this.inFlight = false;
        this.refreshing = false;
        this.store.setState({ status: 'ready', activeRun: null, confirmation: null });
        if (run) void this.cancel(run);
    };

    private cancel(run: AgentRunReference): Promise<void> {
        return (this.tenantId === null ? chatApi.cancelAgentRun(run.cancel_url)
            : chatApi.cancelAgentRun(run.cancel_url, this.tenantId)).catch(() => undefined);
    }

    continueRun = async (options: TurnOptions, runId?: string): Promise<void> => {
        const { activeRun: run, confirmation, status } = this.store.getState();
        const watch = this.sessionWatches.get(runId ?? run?.run_id ?? '');
        if (watch) {
            const extension = watch.run.confirmation;
            if (!extension) return;
            const args = [watch.run.continue_url, extension.physicalExtension, extension.logicalExtension] as const;
            await (this.tenantId === null ? chatApi.continueAgentRun(...args) : chatApi.continueAgentRun(...args, this.tenantId));
            watch.controller?.abort();
            delete watch.controller;
            watch.run = { ...watch.run, status: 'queued', confirmation: null };
            this.syncSessionRuns();
            this.watchSessionRun(watch);
            return;
        }
        if (runId) return;
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

    suspend(): void {
        for (const watch of this.sessionWatches.values()) {
            watch.controller?.abort();
            delete watch.controller;
        }
        // Keep the one-off startup POST alive until its durable run id arrives.
        // Leaving the view must never cancel the server-owned request.
        if (this.inFlight && this.store.getState().status === 'submitted' && !this.store.getState().activeRun) return;
        ++this.generation;
        this.controller?.abort();
        this.inFlight = false;
        this.refreshing = false;
    }

    resumeVisible(): void {
        for (const watch of this.sessionWatches.values()) this.watchSessionRun(watch);
        if (this.sessionWatches.size > 0) return;
        const run = this.store.getState().activeRun;
        if (!this.disposed && this.viewers > 0 && !this.inFlight && this.store.getState().confirmation === null && run
            && document.visibilityState !== 'hidden'
            && !['completed', 'partial', 'failed', 'cancelled', 'awaiting_confirmation'].includes(run.status)) {
            this.watch(run);
        }
    }

    dispose(): void {
        this.disposed = true;
        ++this.generation;
        this.controller?.abort();
        for (const watch of this.sessionWatches.values()) watch.controller?.abort();
    }

    private fetchEvents(url: string, after: number, signal: AbortSignal): Promise<Response> {
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest', 'Last-Event-ID': String(after),
        };
        if (this.tenantId !== null) headers['X-Tenant-Id'] = this.tenantId;
        return fetch(`${url}${url.includes('?') ? '&' : '?'}after=${after}&transport=poll`, {
            method: 'GET', credentials: 'same-origin', cache: 'no-store', headers, signal,
        });
    }
}

function confirmationFrom(event: AgentRunEvent): AgentConfirmation {
    const raw = event.data.extension ?? event.data.requested_extension;
    const extension = typeof raw === 'object' && raw !== null ? raw as Record<string, unknown> : {};
    return {
        physicalExtension: Math.max(1, Number(extension.physical_extension ?? extension.physical ?? 25)),
        logicalExtension: Math.max(0, Number(extension.logical_extension ?? extension.logical ?? 0)),
    };
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

export function setAgentChatVisibility(visible: boolean): void {
    for (const session of sessions.values()) {
        if (visible) session.resumeVisible();
        else session.suspend();
    }
}

export function clearAgentChatSessions(): void {
    for (const session of sessions.values()) session.dispose();
    sessions.clear();
}

// Logout discards transcripts. Team switches keep runs with their own tenant.
useTeamStore.subscribe((state, previous) => {
    if (state.userId !== previous.userId) clearAgentChatSessions();
});
