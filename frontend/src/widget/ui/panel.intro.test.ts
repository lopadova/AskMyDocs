import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { WidgetPanel } from './panel';

const originalFetch = globalThis.fetch;

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('WidgetPanel introduction lifecycle', () => {
    beforeEach(() => {
        document.body.innerHTML = '<main><div id="root"></div></main>';
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.replaceChildren();
        vi.restoreAllMocks();
    });

    it('shows the resolved introduction and removes it after a suggestion starts the chat', async () => {
        const calls: Array<{ url: string; body?: string }> = [];
        globalThis.fetch = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
            const url = String(input);
            calls.push({ url, body: typeof init.body === 'string' ? init.body : undefined });
            if (url.endsWith('/api/widget/setup')) {
                return json({
                    intro: {
                        enabled: true,
                        variant: 'hero',
                        title: 'Server title',
                        body: 'Official documentation only.',
                        suggestions: [{ label: 'Get started', prompt: 'Explain how to start' }],
                    },
                });
            }
            if (url.endsWith('/api/widget/sessions/start')) {
                return json({
                    session: { id: 'ses_intro', status: 'active' },
                    type: 'message',
                    answer: 'Start here.',
                });
            }
            return json({}, 404);
        }) as unknown as typeof fetch;

        const root = document.querySelector<HTMLElement>('#root')!;
        // Host content overrides the server title, while the server body remains.
        // eslint-disable-next-line no-new
        new WidgetPanel(root, {
            key: 'pk_intro',
            apiBase: 'https://kb.example.com',
            intro: { title: 'Page-specific title' },
        }, 'inline');

        const input = root.querySelector<HTMLTextAreaElement>('[data-testid="askmydocs-widget-input"]')!;
        await vi.waitFor(() => expect(input.disabled).toBe(false));
        expect(root.querySelector('[data-testid="askmydocs-widget-intro"]')).toHaveTextContent('Page-specific title');
        expect(root.querySelector('[data-testid="askmydocs-widget-intro"]')).toHaveTextContent('Official documentation only.');

        const intro = root.querySelector<HTMLElement>('[data-testid="askmydocs-widget-intro"]')!;
        root.querySelector<HTMLButtonElement>('[data-testid="askmydocs-widget-intro-suggestion"]')?.click();
        // The exit class is synchronous; capture the node before the 220 ms
        // fallback removes it so a busy full-suite worker cannot race this
        // transient animation state.
        expect(intro).toHaveClass('amd-intro-exit');
        await vi.waitFor(() => expect(calls.some((call) => call.url.endsWith('/sessions/start'))).toBe(true));
        const start = calls.find((call) => call.url.endsWith('/sessions/start'))!;
        expect(JSON.parse(start.body ?? '{}').message).toBe('Explain how to start');
        await vi.waitFor(() => expect(
            root.querySelector('[data-testid="askmydocs-widget-intro"]'),
        ).toBeNull());
    });

    it('lets the host explicitly disable a server introduction', async () => {
        globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
            if (String(input).endsWith('/setup')) return json({ intro: { enabled: true, title: 'Server' } });
            return json({}, 404);
        }) as unknown as typeof fetch;

        const root = document.querySelector<HTMLElement>('#root')!;
        // eslint-disable-next-line no-new
        new WidgetPanel(root, { key: 'pk_intro', apiBase: 'https://kb.example.com', intro: false }, 'inline');
        await vi.waitFor(() => expect(
            root.querySelector<HTMLTextAreaElement>('[data-testid="askmydocs-widget-input"]')?.disabled,
        ).toBe(false));
        expect(root.querySelector('[data-testid="askmydocs-widget-intro"]')).toBeNull();
    });
});
