import { afterEach, describe, expect, it, vi } from 'vitest';
import { Transport } from './transport';

const originalFetch = globalThis.fetch;

afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
});

describe('Transport durable agent endpoints', () => {
    it('starts a widget agent turn and opens its resumable event feed', async () => {
        const calls: Array<{ url: string; init: RequestInit }> = [];
        globalThis.fetch = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
            const url = String(input);
            calls.push({ url, init });
            if (url.endsWith('/sessions/agent/start')) {
                return new Response(JSON.stringify({
                    session: { id: 'session-1', status: 'active', locale: 'it-IT' },
                    type: 'agent_run',
                    run: {
                        id: 'run-1', status: 'queued', locale: 'it-IT',
                        events_url: '/api/widget/sessions/session-1/agent-runs/run-1/events',
                        cancel_url: '/api/widget/sessions/session-1/agent-runs/run-1/cancel',
                        continue_url: '/api/widget/sessions/session-1/agent-runs/run-1/continue',
                    },
                }), { status: 202, headers: { 'Content-Type': 'application/json' } });
            }

            return new Response(': keep-alive\n\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } });
        }) as typeof fetch;
        const transport = new Transport({ key: 'pk_agent', apiBase: 'https://kb.example.com' });
        const started = await transport.startAgent({
            snapshot_id: 'snapshot-1',
            captured_at: new Date().toISOString(),
            page: { url: 'https://host.test', title: 'Host' },
            viewport: { width: 1280, height: 800, scrollY: 0, maxScrollY: 0 },
            active_context: { region: null, locale: 'it-IT', focus_field: null, modal: null },
            regions: [], fields: [], actions: [], messages: [], locales_available: ['it'],
            page_outline: {
                url: 'https://host.test', title: 'Host', headings: [], breadcrumbs: [],
                buttons_unannotated: [], inputs_unannotated: [],
            },
        }, 'Ordini');
        const controller = new AbortController();
        await transport.openAgentEvents(started.run.events_url, 7, controller.signal);

        expect(calls[0].url).toBe('https://kb.example.com/api/widget/sessions/agent/start');
        expect(JSON.parse(String(calls[0].init.body))).toMatchObject({ message: 'Ordini' });
        expect(new Headers(calls[0].init.headers).get('X-Widget-Key')).toBe('pk_agent');
        expect(calls[1].url).toBe('https://kb.example.com/api/widget/sessions/session-1/agent-runs/run-1/events?after=7');
        expect(new Headers(calls[1].init.headers).get('Accept')).toBe('text/event-stream, application/json');
        expect(new Headers(calls[1].init.headers).get('Last-Event-ID')).toBe('7');
    });
});
