/**
 * M5.13 — Transport: token-based auth (wt_…) vs pk mode.
 *
 * Tests:
 *   (a) Transport sends pk in X-Widget-Key header by default
 *   (b) Transport sends Authorization: Bearer wt_… when session token is set
 *   (c) Session token is consumed after one use
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { Transport, WidgetError } from './transport';
import type { WidgetConfig } from '../types';

// Stub global fetch so we can inspect headers/URLs without real network
const originalFetch = globalThis.fetch;

function mockFetch(status: number, body: Record<string, unknown>): typeof fetch {
    return Object.assign(
        vi.fn(async (_url: RequestInfo | URL, _init?: RequestInit) => {
            return new Response(JSON.stringify(body), {
                status,
                headers: { 'Content-Type': 'application/json' },
            });
        }),
        { originalFetch },
    ) as unknown as typeof fetch;
}

// Helper to extract the init (headers, body, method) passed to fetch
function lastCall(fetchMock: ReturnType<typeof mockFetch>): { url: string; init: RequestInit } {
    const calls = (fetchMock as ReturnType<typeof vi.fn>).mock.calls;
    const last = calls[calls.length - 1];
    return { url: String(last[0]), init: last[1] as RequestInit };
}

const baseConfig: WidgetConfig = {
    key: 'pk_test_abc123',
    apiBase: 'https://kb.example.com',
};

describe('Transport', () => {
    let fetchMock: ReturnType<typeof mockFetch>;

    beforeEach(() => {
        fetchMock = mockFetch(200, { session: { id: 'ses_1', status: 'active' }, type: 'message', answer: 'ok' });
        globalThis.fetch = fetchMock;
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        vi.restoreAllMocks();
    });

    // --- (a) pk mode: X-Widget-Key header by default ---

    it('sends X-Widget-Key header with pk_ value by default', async () => {
        const t = new Transport(baseConfig);
        await t.setup();

        const { init } = lastCall(fetchMock);
        const headers = init.headers as Record<string, string>;
        expect(headers['X-Widget-Key']).toBe('pk_test_abc123');
        expect(headers['Authorization']).toBeUndefined();
    });

    it('does not send Authorization header in pk mode', async () => {
        const t = new Transport(baseConfig);
        await t.setup();

        const { init } = lastCall(fetchMock);
        const headers = init.headers as Record<string, string>;
        expect(headers['Authorization']).toBeUndefined();
    });

    it('sends X-Widget-Key on every request in pk mode', async () => {
        const t = new Transport(baseConfig);
        await t.setup();
        await t.start({ snapshot_id: '', captured_at: '', page: { url: '', title: '' }, viewport: { width: 0, height: 0, scrollY: 0, maxScrollY: 0 }, active_context: { region: null, locale: null, focus_field: null, modal: null }, regions: [], fields: [], actions: [], messages: [], locales_available: [], page_outline: { url: '', title: '', headings: [], breadcrumbs: [], buttons_unannotated: [], inputs_unannotated: [] } } as any, 'hi');

        // Both calls should have X-Widget-Key
        const calls = (fetchMock as ReturnType<typeof vi.fn>).mock.calls;
        for (const call of calls) {
            const init = call[1] as RequestInit;
            const headers = init.headers as Record<string, string>;
            expect(headers['X-Widget-Key']).toBe('pk_test_abc123');
            expect(headers['Authorization']).toBeUndefined();
        }
    });

    // --- (b) token mode: Authorization: Bearer wt_… when session token is set ---

    it('sends Authorization: Bearer wt_… when session token is set', async () => {
        const t = new Transport(baseConfig);
        t.setSessionToken('wt_test_session_token_xyz');

        await t.setup();

        const { init } = lastCall(fetchMock);
        const headers = init.headers as Record<string, string>;
        expect(headers['Authorization']).toBe('Bearer wt_test_session_token_xyz');
        expect(headers['X-Widget-Key']).toBeUndefined();
    });

    it('uses pk mode again after session token is consumed', async () => {
        const t = new Transport(baseConfig);
        t.setSessionToken('wt_onetime');

        // First request uses the token
        await t.setup();
        const call1 = lastCall(fetchMock);
        const headers1 = call1.init.headers as Record<string, string>;
        expect(headers1['Authorization']).toBe('Bearer wt_onetime');
        expect(headers1['X-Widget-Key']).toBeUndefined();

        // Second request falls back to pk mode (token consumed)
        await t.setup();
        const call2 = lastCall(fetchMock);
        const headers2 = call2.init.headers as Record<string, string>;
        expect(headers2['X-Widget-Key']).toBe('pk_test_abc123');
        expect(headers2['Authorization']).toBeUndefined();
    });

    // --- (c) token is consumed after use ---

    it('consumes session token after a single request', async () => {
        const t = new Transport(baseConfig);
        t.setSessionToken('wt_consumable');

        // Token is present before first request
        expect(t.getSessionToken()).toBe('wt_consumable');

        // First request consumes it
        await t.setup();

        // Token is null after first request
        expect(t.getSessionToken()).toBeNull();
    });

    it('consumes token on any request method (start, step, execTool, cancel)', async () => {
        // jsdom Response doesn't accept 204 — use 200 with empty body
        const cancelMock = mockFetch(200, {});
        globalThis.fetch = cancelMock;

        const t = new Transport(baseConfig);

        // Test start
        t.setSessionToken('wt_for_start');
        await t.start({ snapshot_id: '', captured_at: '', page: { url: '', title: '' }, viewport: { width: 0, height: 0, scrollY: 0, maxScrollY: 0 }, active_context: { region: null, locale: null, focus_field: null, modal: null }, regions: [], fields: [], actions: [], messages: [], locales_available: [], page_outline: { url: '', title: '', headings: [], breadcrumbs: [], buttons_unannotated: [], inputs_unannotated: [] } } as any, 'hi');
        expect(t.getSessionToken()).toBeNull();

        // Test step
        const stepMock = mockFetch(200, { session: { id: 'ses_1', status: 'active' }, type: 'message', answer: 'ok' });
        globalThis.fetch = stepMock;
        t.setSessionToken('wt_for_step');
        await t.step('ses_1', { snapshot_id: '', captured_at: '', page: { url: '', title: '' }, viewport: { width: 0, height: 0, scrollY: 0, maxScrollY: 0 }, active_context: { region: null, locale: null, focus_field: null, modal: null }, regions: [], fields: [], actions: [], messages: [], locales_available: [], page_outline: { url: '', title: '', headings: [], breadcrumbs: [], buttons_unannotated: [], inputs_unannotated: [] } } as any, 'msg', null);
        expect(t.getSessionToken()).toBeNull();

        // Test execTool
        const execMock = mockFetch(200, { artifact: { componentType: 'ui-data-table', componentProps: {} }, has_results: false, interaction_mode: 'view' });
        globalThis.fetch = execMock;
        t.setSessionToken('wt_for_exec');
        await t.execTool('ses_1', 'search_knowledge_base', { query: 'test' });
        expect(t.getSessionToken()).toBeNull();

        // Test cancel
        globalThis.fetch = cancelMock;
        t.setSessionToken('wt_for_cancel');
        await t.cancel('ses_1');
        expect(t.getSessionToken()).toBeNull();
    });

    // --- mintSessionToken ---

    it('mints a session token via POST /session-token using pk headers', async () => {
        const mintMock = mockFetch(201, { token: 'wt_minted_fresh', expires_at: '2026-06-01T00:00:00Z' });
        globalThis.fetch = mintMock;

        const t = new Transport(baseConfig);
        const result = await t.mintSessionToken();

        expect(result.token).toBe('wt_minted_fresh');
        expect(t.getSessionToken()).toBe('wt_minted_fresh');

        // The mint request itself must use pk headers (not token)
        const { init } = lastCall(mintMock);
        const headers = init.headers as Record<string, string>;
        expect(headers['X-Widget-Key']).toBe('pk_test_abc123');
        expect(headers['Authorization']).toBeUndefined();
    });

    // --- WidgetError on non-OK responses ---

    it('throws WidgetError on non-2xx responses', async () => {
        globalThis.fetch = mockFetch(401, { error: 'widget_key_invalid', message: 'Unknown widget key.' });

        const t = new Transport(baseConfig);
        await expect(t.setup()).rejects.toThrow(WidgetError);
    });

    // --- #17: timeout/AbortController wiring ---

    it('#17 — passes an AbortSignal to fetch (timeout wiring)', async () => {
        const t = new Transport(baseConfig);
        await t.setup();

        const { init } = lastCall(fetchMock);
        expect(init.signal).toBeInstanceOf(AbortSignal);
    });

    it('#17 — an aborted fetch surfaces as a WidgetError with code "timeout"', async () => {
        globalThis.fetch = vi.fn(async () => {
            throw new DOMException('The operation was aborted.', 'AbortError');
        }) as unknown as typeof fetch;

        const t = new Transport(baseConfig);
        await expect(t.setup()).rejects.toMatchObject({ code: 'timeout' });
    });
});