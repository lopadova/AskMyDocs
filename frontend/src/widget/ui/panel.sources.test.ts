import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { WidgetPanel } from './panel';

const originalFetch = globalThis.fetch;

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('WidgetPanel cited source viewer', () => {
    beforeEach(() => {
        document.body.innerHTML = '<main><div id="root"></div></main>';
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.replaceChildren();
        vi.restoreAllMocks();
    });

    it('turns citations into buttons and opens the real session-scoped document', async () => {
        const urls: string[] = [];
        globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            urls.push(url);
            if (url.endsWith('/api/widget/setup')) return json({});
            if (url.endsWith('/api/widget/sessions/start')) {
                return json({
                    session: { id: 'ses_sources', status: 'active' },
                    type: 'message',
                    answer: 'La decisione usa Redis.',
                    citations: [
                        {
                            document_id: 7,
                            title: 'Cache decision',
                            source_path: 'decisions/cache.md',
                            source_type: 'markdown',
                            origin: 'primary',
                            chunks: [{ chunk_id: 70, heading: 'Decision', snippet: 'Redis keeps hot reads fast.', evidence_hash: 'a' }],
                        },
                    ],
                });
            }
            if (url.endsWith('/api/widget/sessions/ses_sources/documents/7/preview')) {
                return json({
                    document_id: 7,
                    title: 'Cache decision',
                    source_path: 'decisions/cache.md',
                    source_type: 'markdown',
                    language: 'en',
                    source_updated_at: null,
                    sections: [{ heading_path: 'Decision', content: '**Redis** is used for hot reads.' }],
                });
            }

            return json({ error: 'unexpected', message: url }, 500);
        }) as unknown as typeof fetch;

        const root = document.querySelector<HTMLElement>('#root')!;
        // eslint-disable-next-line no-new
        new WidgetPanel(root, { key: 'pk_sources', apiBase: 'https://kb.example.com' }, 'inline');
        const input = root.querySelector<HTMLTextAreaElement>('[data-testid="askmydocs-widget-input"]')!;
        await vi.waitFor(() => expect(input.disabled).toBe(false));
        input.value = 'Quale cache usiamo?';
        input.closest('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => expect(
            root.querySelector<HTMLButtonElement>('[data-testid="askmydocs-widget-citation"]'),
        ).not.toBeNull());
        const citation = root.querySelector<HTMLButtonElement>('[data-testid="askmydocs-widget-citation"]');
        expect(citation).toHaveAttribute('data-openable', 'true');
        citation?.click();

        await vi.waitFor(() => {
            expect(root.querySelector('[data-testid="askmydocs-widget-source-content"]')).toHaveAttribute('data-state', 'ready');
        });
        expect(root.querySelector('.amd-source-title')).toHaveTextContent('Cache decision');
        expect(root.querySelector('.amd-source-markdown strong')).toHaveTextContent('Redis');
        expect(root.querySelector('[data-testid="askmydocs-widget-source-evidence"]')).toHaveTextContent('Redis keeps hot reads fast.');
        expect(urls).toContain('https://kb.example.com/api/widget/sessions/ses_sources/documents/7/preview');
    });

    it('keeps legacy citations without a document id non-interactive', async () => {
        globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            if (url.endsWith('/setup')) return json({});
            if (url.endsWith('/sessions/start')) {
                return json({
                    session: { id: 'ses_legacy', status: 'active' },
                    type: 'message',
                    answer: 'Legacy answer',
                    citations: [{ document_id: null, title: 'Legacy source', source_path: null }],
                });
            }
            return json({}, 404);
        }) as unknown as typeof fetch;

        const root = document.querySelector<HTMLElement>('#root')!;
        // eslint-disable-next-line no-new
        new WidgetPanel(root, { key: 'pk_sources', apiBase: 'https://kb.example.com' }, 'inline');
        const input = root.querySelector<HTMLTextAreaElement>('[data-testid="askmydocs-widget-input"]')!;
        await vi.waitFor(() => expect(input.disabled).toBe(false));
        input.value = 'Legacy';
        input.closest('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => expect(
            root.querySelector<HTMLElement>('[data-testid="askmydocs-widget-citation"]'),
        ).not.toBeNull());
        const citation = root.querySelector<HTMLElement>('[data-testid="askmydocs-widget-citation"]');
        expect(citation?.tagName).toBe('SPAN');
        expect(root.querySelector('[data-testid="askmydocs-widget-sources-open"]')).toBeNull();
    });

    it('keeps grounded source buttons visible when the agent emits a terminal tool call', async () => {
        globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            if (url.endsWith('/setup')) return json({});
            if (url.endsWith('/sessions/start')) {
                return json({
                    session: { id: 'ses_tool_sources', status: 'completed' },
                    type: 'tool_call',
                    bot_message: 'Ho verificato la procedura.',
                    citations: [{
                        document_id: 9,
                        title: 'Procedure guide',
                        source_path: 'docs/procedure.md',
                    }],
                    tool_call: {
                        tool: 'report_done',
                        args: { summary: 'Verifica completata' },
                        confirmation_required: false,
                        is_be_tool: false,
                    },
                });
            }
            return json({}, 404);
        }) as unknown as typeof fetch;

        const root = document.querySelector<HTMLElement>('#root')!;
        // eslint-disable-next-line no-new
        new WidgetPanel(root, { key: 'pk_sources', apiBase: 'https://kb.example.com' }, 'inline');
        const input = root.querySelector<HTMLTextAreaElement>('[data-testid="askmydocs-widget-input"]')!;
        await vi.waitFor(() => expect(input.disabled).toBe(false));
        input.value = 'Verifica la procedura';
        input.closest('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => expect(
            root.querySelector('[data-testid="askmydocs-widget-sources-open"]'),
        ).not.toBeNull());
        expect(root.querySelector('[data-testid="askmydocs-widget-citation"]')).toHaveTextContent('Procedure guide');
        expect(root.querySelector('[data-testid="askmydocs-widget-system"]')).toHaveTextContent('Verifica completata');
    });
});
