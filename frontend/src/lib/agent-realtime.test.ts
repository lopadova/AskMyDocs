import { afterEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    get: vi.fn(), post: vi.fn(), clients: [] as Array<{ bind: ReturnType<typeof vi.fn>; options: any }>,
    echoes: [] as Array<{ disconnect: ReturnType<typeof vi.fn>; receive?: (value: unknown) => void; channel?: string }>,
}));
vi.mock('./api', () => ({ api: { get: mocks.get, post: mocks.post } }));
vi.mock('pusher-js', () => ({ default: class {
    connection = { bind: vi.fn() };
    constructor(_key: string, options: unknown) { mocks.clients.push({ bind: this.connection.bind, options }); }
} }));
vi.mock('laravel-echo', () => ({ default: class {
    disconnect = vi.fn();
    constructor() { mocks.echoes.push(this); }
    private(channel: string) { return { listen: (_name: string, receive: (value: unknown) => void) => {
        Object.assign(this, { channel, receive });
    } }; }
} }));
import { subscribeAgentActivity, waitForAgentUpdate } from './agent-realtime';

const config = { enabled: true, key: 'public-key', host: 'socket.example.test', port: 443, scheme: 'https',
    channel: 'agent.activity.acme.1', auth_endpoint: '/agent-transport/auth' };
const leaves: Array<() => void> = [];
afterEach(() => {
    leaves.splice(0).forEach((leave) => leave());
    mocks.get.mockReset(); mocks.post.mockReset(); mocks.clients.length = 0; mocks.echoes.length = 0;
    vi.useRealTimers();
});
const flush = async () => { await Promise.resolve(); await Promise.resolve(); };

describe('shared Reverb activity connection', () => {
    it('shares one scoped socket, signs with the owning tenant, wakes replay, and disconnects the last listener', async () => {
        vi.useFakeTimers();
        mocks.get.mockResolvedValue({ data: config });
        mocks.post.mockResolvedValue({ data: { auth: 'signature' } });
        const first = vi.fn();
        const leave = subscribeAgentActivity(1, 'acme', first); leaves.push(leave);
        const secondLeave = subscribeAgentActivity(1, 'acme', vi.fn()); leaves.push(secondLeave);
        await flush();
        expect(mocks.get).toHaveBeenCalledOnce();
        expect(mocks.clients).toHaveLength(1);
        expect(mocks.echoes[0].channel).toBe('agent.activity.acme.1');
        const callback = vi.fn();
        mocks.clients[0].options.channelAuthorization.customHandler({ socketId: '1.2', channelName: 'private-agent.activity.acme.1' }, callback);
        await flush();
        expect(mocks.post).toHaveBeenCalledWith('/agent-transport/auth', { socket_id: '1.2', channel_name: 'private-agent.activity.acme.1' },
            expect.objectContaining({ headers: { 'X-Tenant-Id': 'acme' } }));
        const controller = new AbortController();
        const awake = vi.fn();
        const waiting = waitForAgentUpdate('run-1', controller.signal).then(awake);
        mocks.echoes[0].receive!({ run_id: 'run-1', sequence: 12, conversation_id: 7 });
        await waiting;
        expect(awake).toHaveBeenCalledOnce();
        expect(vi.getTimerCount()).toBe(0);
        leave(); expect(mocks.echoes[0].disconnect).not.toHaveBeenCalled();
        secondLeave(); expect(mocks.echoes[0].disconnect).toHaveBeenCalledOnce();
    });

    it('ignores late config from a previous tenant and uses a bounded fallback when disabled', async () => {
        vi.useFakeTimers();
        let resolveOld!: (value: unknown) => void;
        mocks.get.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; }))
            .mockResolvedValueOnce({ data: { ...config, enabled: false } });
        leaves.push(subscribeAgentActivity(1, 'acme', vi.fn()));
        leaves.push(subscribeAgentActivity(1, 'other', vi.fn()));
        resolveOld({ data: config }); await flush();
        expect(mocks.clients).toHaveLength(0);
        const controller = new AbortController();
        const awake = vi.fn();
        const waiting = waitForAgentUpdate('run-1', controller.signal).then(awake);
        await vi.advanceTimersByTimeAsync(2999); expect(awake).not.toHaveBeenCalled();
        controller.abort(); await waiting;
        expect(vi.getTimerCount()).toBe(0);
    });
});
