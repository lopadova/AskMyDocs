import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { WidgetPanel } from './panel';

const originalFetch = globalThis.fetch;
const encoder = new TextEncoder();

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function frame(event: AgentRunEvent): Uint8Array {
    return encoder.encode(`id: ${event.sequence}\nevent: ${event.type}\ndata: ${JSON.stringify(event)}\n\n`);
}

describe('WidgetPanel agent activity', () => {
    beforeEach(() => {
        document.body.innerHTML = '<main><h1>Orders</h1><div id="widget-root"></div></main>';
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.body.replaceChildren();
        vi.restoreAllMocks();
    });

    it('routes connector-enabled projects through the agent and renders localized progress', async () => {
        let stream!: ReadableStreamDefaultController<Uint8Array>;
        let eventFeedOpened = false;
        globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            if (url.endsWith('/api/widget/setup')) {
                return json({ data_agent: { enabled: true, tool_count: 1 }, intro: { enabled: false } });
            }
            if (url.endsWith('/api/widget/sessions/agent/start')) {
                return json({
                    session: { id: 'session-1', status: 'active', locale: 'it-IT' },
                    type: 'agent_run',
                    run: {
                        id: 'run-1', status: 'queued', locale: 'it-IT',
                        events_url: '/api/widget/sessions/session-1/agent-runs/run-1/events',
                        cancel_url: '/api/widget/sessions/session-1/agent-runs/run-1/cancel',
                        continue_url: '/api/widget/sessions/session-1/agent-runs/run-1/continue',
                    },
                }, 202);
            }
            if (url.includes('/agent-runs/run-1/events')) {
                eventFeedOpened = true;
                return new Response(new ReadableStream<Uint8Array>({ start(controller) { stream = controller; } }), {
                    status: 200,
                    headers: { 'Content-Type': 'text/event-stream' },
                });
            }

            return json({ error: 'unexpected', message: url }, 500);
        }) as typeof fetch;

        const root = document.querySelector<HTMLElement>('#widget-root')!;
        // eslint-disable-next-line no-new
        new WidgetPanel(root, { key: 'pk_agent', apiBase: 'https://kb.example.com' }, 'inline');
        const input = root.querySelector<HTMLTextAreaElement>('[data-testid="askmydocs-widget-input"]')!;
        await vi.waitFor(() => expect(input.disabled).toBe(false));
        input.value = 'Dammi gli ordini di Tizio';
        input.closest('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await vi.waitFor(() => expect(eventFeedOpened).toBe(true));

        stream.enqueue(frame({
            run_id: 'run-1', sequence: 1, type: 'tool.progress', phase: 'tool', locale: 'it-IT',
            message_key: 'tool.progress', message_params: {}, message: 'Completate 3 richieste API su circa 10.',
            progress: {
                logical: { completed: 1, estimated: { min: 1, likely: 2, max: 3 } },
                physical: { completed: 3, estimated: { min: 5, likely: 10, max: 20 } },
                eta_ms: 5000,
            },
            can_cancel: true, data: { tool: 'list_orders' }, created_at: null,
        }));
        await vi.waitFor(() => {
            expect(root.querySelector('[data-testid="askmydocs-widget-agent-message"]')?.textContent).toContain('Completate 3');
            expect(root.querySelector<HTMLElement>('[data-testid="askmydocs-widget-agent-progress"]')?.style.width).toBe('30%');
        });

        stream.enqueue(frame({
            run_id: 'run-1', sequence: 2, type: 'run.completed', phase: 'run', locale: 'it-IT',
            message_key: 'run.completed', message_params: {}, message: 'La risposta è pronta.', progress: null,
            can_cancel: false,
            data: { response: {
                answer: 'Ho trovato l’ordine A-100.',
                citations: [],
                tool_sources: [{ tool: 'list_orders', label: 'ERP · Ordini' }],
            } },
            created_at: null,
        }));
        stream.close();

        await vi.waitFor(() => {
            expect(root.querySelector('[data-testid="askmydocs-widget-agent-activity"]')).toHaveAttribute('data-state', 'settled');
            expect(root.textContent).toContain('Ho trovato l’ordine A-100.');
            expect(root.textContent).toContain('ERP · Ordini');
        });
    });
});
