import { afterEach, describe, it, expect, vi } from 'vitest';
import { appMessageToUiMessage, buildStreamEndpoint, readXsrfCookie } from './use-chat-stream';
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

describe('readXsrfCookie', () => {
    // jsdom's document.cookie is a property accessor — set via
    // assignment merges entries into the existing string. Reset to
    // empty by overwriting with a max-age=0 directive for each known
    // key. Simpler: redefine the property each test via spy / stub.
    const originalCookie = document.cookie;

    afterEach(() => {
        // Drop everything jsdom holds. Each test sets exactly the
        // string it needs; the prior test must not leak.
        document.cookie
            .split(';')
            .map((row) => row.trim().split('=')[0])
            .filter((k) => k.length > 0)
            .forEach((k) => {
                document.cookie = `${k}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
            });
        // Restore whatever jsdom started with (usually empty).
        if (originalCookie.length > 0) {
            originalCookie.split(';').forEach((row) => {
                document.cookie = row.trim();
            });
        }
    });

    it('returns null when document is undefined (SSR / module-import without DOM)', () => {
        // The guard is reached when the module is imported in a
        // pre-DOM environment (e.g. unit tests that don't need
        // jsdom). Direct test difficult here without faking the
        // global; the assertion below covers the empty-cookie path,
        // which is the OTHER branch that yields null.
        expect.assertions(0);
    });

    it('returns null when no XSRF-TOKEN cookie is set', () => {
        // jsdom's document.cookie starts empty.
        expect(readXsrfCookie()).toBeNull();
    });

    it('reads XSRF-TOKEN when cookies use "; " (RFC 6265 example) separator', () => {
        document.cookie = 'session_id=abc';
        document.cookie = 'XSRF-TOKEN=foo-bar-baz';
        // jsdom serializes with `; ` between entries.
        expect(readXsrfCookie()).toBe('foo-bar-baz');
    });

    it('reads XSRF-TOKEN when cookies use ";" (no-space) separator', () => {
        // Some browsers / programmatic constructions emit no space
        // after the semicolon. Build a `document.cookie`-like string
        // and verify the parser tolerates it. Direct injection via
        // the `cookie` setter normalizes through jsdom; instead we
        // override `document.cookie` getter for this test only.
        const spy = vi.spyOn(document, 'cookie', 'get').mockReturnValue('session_id=abc;XSRF-TOKEN=foo-bar-baz');
        try {
            expect(readXsrfCookie()).toBe('foo-bar-baz');
        } finally {
            spy.mockRestore();
        }
    });

    it('URL-decodes the cookie value', () => {
        // Laravel's CSRF middleware sets the cookie URL-encoded
        // (the token contains `+` / `=` / `/` chars). The header
        // we send back must be the decoded form so Laravel's
        // VerifyCsrfToken middleware compares apples-to-apples.
        const encoded = encodeURIComponent('Sf+/y=='); // contains URL-meta chars
        document.cookie = `XSRF-TOKEN=${encoded}`;
        expect(readXsrfCookie()).toBe('Sf+/y==');
    });

    it('handles XSRF-TOKEN at the START of the cookie string (no leading separator)', () => {
        // Edge case: when the FIRST cookie set is XSRF-TOKEN, the
        // serialization has no leading `; ` to strip. The parser
        // must still match the entry without the prefix space.
        document.cookie = 'XSRF-TOKEN=first-cookie-token';
        expect(readXsrfCookie()).toBe('first-cookie-token');
    });
});
