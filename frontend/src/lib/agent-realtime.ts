import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { api } from './api';

export interface AgentUpdate { run_id: string; sequence: number; conversation_id: number | null }
interface TransportConfig {
    enabled: boolean; key: string | null; host: string; port: number; scheme: string;
    channel: string; auth_endpoint: string;
}
interface Subscription {
    scope: string; listeners: Set<(update: AgentUpdate) => void>; stopped: boolean;
    controller: AbortController; echo?: Echo<'reverb'>; connected: boolean;
}
let subscription: Subscription | null = null;
const waiters = new Map<string, Set<() => void>>();

/** One socket per visible authenticated app, never one PHP connection per run. */
export function subscribeAgentActivity(userId: number, tenantId: string, listener: (update: AgentUpdate) => void): () => void {
    const scope = JSON.stringify([userId, tenantId]);
    if (subscription?.scope !== scope) {
        stopSubscription();
        const current: Subscription = { scope, listeners: new Set(), stopped: false, connected: false, controller: new AbortController() };
        subscription = current;
        void connect(current, tenantId);
    }
    const current = subscription!;
    current.listeners.add(listener);
    return () => {
        current.listeners.delete(listener);
        if (current.listeners.size === 0 && subscription === current) stopSubscription();
    };
}

async function connect(current: Subscription, tenantId: string): Promise<void> {
    try {
        const { data: config } = await api.get<TransportConfig>('/agent-transport', {
            headers: { 'X-Tenant-Id': tenantId }, signal: current.controller.signal,
        });
        if (current.stopped || !config.enabled || !config.key) return;
        const client = new Pusher(config.key, {
            cluster: 'mt1', wsHost: config.host, wsPort: config.port, wssPort: config.port,
            forceTLS: config.scheme === 'https', enabledTransports: ['ws', 'wss'], disableStats: true,
            channelAuthorization: {
                customHandler: (params, callback) => {
                    void api.post(config.auth_endpoint, { socket_id: params.socketId, channel_name: params.channelName }, {
                        headers: { 'X-Tenant-Id': tenantId }, signal: current.controller.signal,
                    }).then((response) => callback(null, response.data))
                        .catch(() => callback(new Error('Agent channel authorization failed'), null));
                },
            },
        });
        current.echo = new Echo({ broadcaster: 'reverb', client, withoutInterceptors: true });
        client.connection.bind('state_change', ({ current: state }: { current: string }) => {
            current.connected = state === 'connected';
            // Replay after reconnect as well as after individual notifications.
            if (current.connected) for (const listener of current.listeners) listener({ run_id: '', sequence: 0, conversation_id: null });
        });
        current.echo.private(config.channel).listen('.agent.run.updated', (update: AgentUpdate) => {
            if (current.stopped || typeof update.run_id !== 'string' || !Number.isInteger(update.sequence)) return;
            for (const wake of waiters.get(update.run_id) ?? []) wake();
            for (const listener of current.listeners) listener(update);
        });
    } catch {
        // Bounded HTTP replay remains available when Reverb is not configured/offline.
    }
}

function stopSubscription(): void {
    if (!subscription) return;
    subscription.stopped = true;
    subscription.controller.abort();
    subscription.echo?.disconnect();
    subscription = null;
    for (const listeners of waiters.values()) for (const wake of [...listeners]) wake();
}

export function waitForAgentUpdate(runId: string, signal: AbortSignal): Promise<void> {
    return new Promise((resolve) => {
        if (signal.aborted) { resolve(); return; }
        const listeners = waiters.get(runId) ?? new Set<() => void>();
        const done = () => {
            clearTimeout(timer);
            signal.removeEventListener('abort', done);
            listeners.delete(done);
            if (listeners.size === 0) waiters.delete(runId);
            resolve();
        };
        const timer = setTimeout(done, subscription?.connected ? 15_000 : 3_000);
        listeners.add(done);
        waiters.set(runId, listeners);
        signal.addEventListener('abort', done, { once: true });
    });
}
