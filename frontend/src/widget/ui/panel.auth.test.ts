import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { WidgetConfig } from '../types';
import { WidgetPanel } from './panel';

const originalFetch = globalThis.fetch;

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function authenticatedConfig(): WidgetConfig {
    return {
        key: 'pk_widget_test',
        apiBase: 'https://kb.example.com',
        userTokenUrl: '/api/askmydocs/widget-user-token',
    };
}

describe('WidgetPanel authenticated-user bootstrap', () => {
    beforeEach(() => {
        document.body.innerHTML = '<main><h1>Host page</h1><div id="widget-root"></div></main>';
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.replaceChildren();
        vi.restoreAllMocks();
    });

    it('blocks the first submit until identity history restore completes', async () => {
        const tokenUrl = new URL('/api/askmydocs/widget-user-token', window.location.href).toString();
        const calls: Array<{ url: string; init: RequestInit }> = [];
        let releaseHistory!: (response: Response) => void;
        const pendingHistory = new Promise<Response>((resolve) => {
            releaseHistory = resolve;
        });

        globalThis.fetch = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
            const url = String(input);
            calls.push({ url, init });
            if (url === tokenUrl) {
                return json({
                    token: 'wu_panel_user',
                    expires_at: new Date(Date.now() + 10 * 60_000).toISOString(),
                });
            }
            if (url.endsWith('/api/widget/setup')) {
                return json({});
            }
            if (url.includes('/api/widget/sessions?page=')) {
                return pendingHistory;
            }
            if (url.endsWith('/sessions/ses_restored/replay')) {
                return json({
                    steps: [
                        { step_index: 0, kind: 'user_message', tool: null, args_json: { content: 'Messaggio precedente' } },
                        { step_index: 1, kind: 'bot_message', tool: null, args_json: { content: 'Risposta precedente' } },
                    ],
                });
            }
            if (url.endsWith('/sessions/ses_restored/step')) {
                return json({
                    session: { id: 'ses_restored', status: 'active' },
                    type: 'message',
                    answer: 'Continuazione',
                });
            }

            return json({ error: 'unexpected_request', message: url }, 500);
        }) as unknown as typeof fetch;

        const root = document.querySelector<HTMLElement>('#widget-root')!;
        // eslint-disable-next-line no-new
        new WidgetPanel(root, authenticatedConfig(), 'inline');

        const panel = root.querySelector<HTMLElement>('[data-testid="askmydocs-widget-panel"]')!;
        const input = root.querySelector<HTMLTextAreaElement>('[data-testid="askmydocs-widget-input"]')!;
        const send = root.querySelector<HTMLButtonElement>('[data-testid="askmydocs-widget-send"]')!;
        const form = input.closest('form')!;

        await vi.waitFor(() => {
            expect(calls.some((call) => call.url.includes('/api/widget/sessions?page='))).toBe(true);
        });
        expect(panel.dataset.state).toBe('loading');
        expect(panel.getAttribute('aria-busy')).toBe('true');
        expect(input.disabled).toBe(true);
        expect(send.disabled).toBe(true);

        input.value = 'Non creare una nuova sessione';
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(calls.some((call) => call.url.endsWith('/sessions/start'))).toBe(false);
        expect(calls.some((call) => call.url.endsWith('/step'))).toBe(false);

        releaseHistory(json({
            data: [{
                id: 'ses_restored',
                status: 'active',
                summary: null,
                page_url: null,
                created_at: '2026-07-27T08:00:00Z',
                updated_at: '2026-07-27T09:00:00Z',
            }],
            meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
        }));

        await vi.waitFor(() => {
            expect(panel.dataset.state).toBe('ready');
            expect(input.disabled).toBe(false);
        });

        input.value = 'Continua';
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => {
            expect(calls.some((call) => call.url.endsWith('/sessions/ses_restored/step'))).toBe(true);
        });
        expect(calls.some((call) => call.url.endsWith('/sessions/start'))).toBe(false);
    });

    it('surfaces invalid token acquisition and keeps the composer fail-closed', async () => {
        globalThis.fetch = vi.fn(async () => json({
            token: 'not-a-user-token',
            expires_at: new Date(Date.now() + 10 * 60_000).toISOString(),
        })) as unknown as typeof fetch;

        const root = document.querySelector<HTMLElement>('#widget-root')!;
        // eslint-disable-next-line no-new
        new WidgetPanel(root, authenticatedConfig(), 'inline');

        const panel = root.querySelector<HTMLElement>('[data-testid="askmydocs-widget-panel"]')!;
        const input = root.querySelector<HTMLTextAreaElement>('[data-testid="askmydocs-widget-input"]')!;
        const send = root.querySelector<HTMLButtonElement>('[data-testid="askmydocs-widget-send"]')!;

        await vi.waitFor(() => {
            expect(panel.dataset.state).toBe('error');
        });

        expect(panel.getAttribute('aria-busy')).toBe('false');
        expect(input.disabled).toBe(true);
        expect(send.disabled).toBe(true);
        expect(root.querySelector('[data-testid="askmydocs-widget-error"]')?.textContent)
            .toContain('sono richiesti token wu_ ed expires_at futuro');
    });
});
