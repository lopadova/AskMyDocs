import { afterEach, describe, expect, it, vi } from 'vitest';
import { Bridge, type BridgeEvents } from './bridge';
import type { WidgetConfig } from '../types';

const originalFetch = globalThis.fetch;

function events(): BridgeEvents {
    return {
        onBusy: vi.fn(),
        onAnswer: vi.fn(),
        onBotText: vi.fn(),
        onAction: vi.fn(),
        onAsk: vi.fn(),
        onDone: vi.fn(),
        onBlocked: vi.fn(),
        onError: vi.fn(),
        onConfirm: vi.fn(),
        onArtifact: vi.fn(),
        onPointAt: vi.fn(),
        onTourStep: vi.fn(),
        onClearOverlay: vi.fn(),
    };
}

const config: WidgetConfig = {
    key: 'pk_history',
    apiBase: 'https://kb.example.com',
    userToken: 'wu_authenticated',
};

afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
});

describe('Bridge authenticated history restore', () => {
    it('uses /sessions/current and replays the selected open session', async () => {
        const urls: string[] = [];
        globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            urls.push(url);
            if (url.endsWith('/sessions/current')) {
                return new Response(JSON.stringify({
                    data: {
                        id: 'open-beyond-page-one',
                        status: 'active',
                        summary: null,
                        page_url: null,
                        created_at: '2026-07-20T10:00:00Z',
                        updated_at: '2026-07-28T10:00:00Z',
                    },
                }), { status: 200, headers: { 'Content-Type': 'application/json' } });
            }
            if (url.endsWith('/sessions/open-beyond-page-one/replay')) {
                return new Response(JSON.stringify({
                    steps: [
                        {
                            step_index: 0,
                            kind: 'user_message',
                            tool: null,
                            args_json: { content: 'Question from the restored session' },
                        },
                        {
                            step_index: 1,
                            kind: 'bot_message',
                            tool: null,
                            args_json: { content: 'Restored answer' },
                        },
                    ],
                }), { status: 200, headers: { 'Content-Type': 'application/json' } });
            }

            return new Response('{}', { status: 404, headers: { 'Content-Type': 'application/json' } });
        }) as unknown as typeof fetch;

        const restored = await new Bridge(config, events()).restoreAuthenticatedSession();

        expect(restored).toEqual([
            { role: 'user', content: 'Question from the restored session' },
            { role: 'assistant', content: 'Restored answer' },
        ]);
        expect(urls).toEqual([
            'https://kb.example.com/api/widget/sessions/current',
            'https://kb.example.com/api/widget/sessions/open-beyond-page-one/replay',
        ]);
        expect(urls.some((url) => url.includes('sessions?page='))).toBe(false);
    });

    it('distinguishes an empty 204 state and does not request replay', async () => {
        const fetchSpy = vi.fn(async () => new Response(null, { status: 204 }));
        globalThis.fetch = fetchSpy as unknown as typeof fetch;

        await expect(
            new Bridge(config, events()).restoreAuthenticatedSession(),
        ).resolves.toEqual([]);
        expect(fetchSpy).toHaveBeenCalledTimes(1);
    });
});
