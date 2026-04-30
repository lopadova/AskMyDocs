import { describe, it, expect } from 'vitest';
import { appMessageToUiMessage, buildStreamEndpoint } from './use-chat-stream';
import type { Message as AppMessage } from './chat.api';

/**
 * v4.0/W3.2 — unit tests for the pure helpers exposed alongside the
 * `useChatStream()` hook. The hook itself integrates with Vercel SDK
 * + DefaultChatTransport + DOM fetch, so its full behaviour test
 * lives in the chat-stream*.spec.ts Playwright scenarios coming in
 * step 5. Here we lock the small adapters that drive the request
 * URL + the SDK-shape conversion.
 */

describe('buildStreamEndpoint', () => {
    it('builds the streaming URL for a real conversation id', () => {
        expect(buildStreamEndpoint(42)).toBe('/conversations/42/messages/stream');
    });

    it('returns an inert sentinel URL when conversationId is null', () => {
        // Allowed so the hook mounts before a conversation exists;
        // callers must avoid sendMessage() in this state. The /0/
        // path 404s on the BE — observable by the test suite when a
        // misbehaving caller does send before the id is set.
        expect(buildStreamEndpoint(null)).toBe('/conversations/0/messages/stream');
    });

    it('id zero produces the same URL as the null sentinel (intentionally reserved)', () => {
        // Conversation ids are expected to be auto-increment positive
        // integers, so `0` is intentionally reserved for the inert
        // pre-conversation URL shape used by the null sentinel above.
        // This test documents that the overlap is deliberate rather
        // than claiming `0` produces a distinct endpoint. If callers
        // need to disambiguate (e.g. a fixture that genuinely tests
        // a "conversation id zero" code path), the helper would
        // need a separate sentinel value (e.g. -1).
        expect(buildStreamEndpoint(0)).toBe(buildStreamEndpoint(null));
    });
});

describe('appMessageToUiMessage', () => {
    function appMsg(overrides: Partial<AppMessage>): AppMessage {
        return {
            id: 1,
            role: 'user',
            content: 'Hello',
            metadata: null,
            rating: null,
            created_at: '2026-04-30T20:00:00Z',
            ...overrides,
        };
    }

    it('converts user message content into a single text part', () => {
        const ui = appMessageToUiMessage(appMsg({ id: 7, role: 'user', content: 'How does PTO work?' }));

        expect(ui.id).toBe('7'); // SDK uses string ids
        expect(ui.role).toBe('user');
        expect(ui.parts).toHaveLength(1);
        expect(ui.parts[0]).toEqual({ type: 'text', text: 'How does PTO work?' });
    });

    it('converts assistant message content into a single text part', () => {
        const ui = appMessageToUiMessage(
            appMsg({ id: 8, role: 'assistant', content: 'PTO accrues at 1.66 days per month.' }),
        );

        expect(ui.role).toBe('assistant');
        expect(ui.parts[0]).toEqual({
            type: 'text',
            text: 'PTO accrues at 1.66 days per month.',
        });
    });

    it('stringifies numeric ids (SDK requires string)', () => {
        // The BE issues numeric ids; the SDK's UIMessage type uses
        // string ids. Stringification must be lossless for any int.
        // The existing data-testid="chat-message-{id}" DOM contract
        // already uses the stringified form, so this conversion
        // matches what the test suite reads.
        expect(appMessageToUiMessage(appMsg({ id: 1234567 })).id).toBe('1234567');
        expect(appMessageToUiMessage(appMsg({ id: -1 })).id).toBe('-1'); // optimistic id
    });

    it('preserves empty content as an empty-text part', () => {
        // Streaming-mid-render edge: useChat() renders a partial
        // assistant message whose content is being progressively
        // appended. A snapshot might capture content === '' before
        // the first text-delta arrives. The conversion must NOT
        // throw or skip the part array.
        const ui = appMessageToUiMessage(appMsg({ id: 9, role: 'assistant', content: '' }));
        expect(ui.parts[0]).toEqual({ type: 'text', text: '' });
    });
});
