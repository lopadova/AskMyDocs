import { useEffect, useMemo, useRef } from 'react';
import { useChat as sdkUseChat, type UseChatHelpers } from '@ai-sdk/react';
import { DefaultChatTransport, type UIMessage } from 'ai';
import { ensureCsrfCookie } from '../../lib/api';
import { isFilterStateEmpty, type FilterState, type Message as AppMessage } from './chat.api';

/**
 * Read the `XSRF-TOKEN` cookie value (URL-decoded) so we can echo it
 * back in the `X-XSRF-TOKEN` request header on the streaming POST.
 * Laravel's CSRF middleware requires this header on state-changing
 * requests under the `web` middleware group; the synchronous chat
 * route works because the shared axios instance forwards the
 * cookie automatically when `withCredentials: true`. The Vercel SDK's
 * `DefaultChatTransport` uses `fetch` instead of axios, so we have
 * to thread the header through manually — without it, the streaming
 * POST 419s.
 *
 * `cookie` access is browser-only (no SSR concern in this SPA), but
 * we still guard against `document` being undefined so unit tests
 * that import this module without a DOM don't crash at module-load
 * time.
 */
function readXsrfCookie(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }
    const match = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
    if (match === undefined) {
        return null;
    }
    return decodeURIComponent(match.slice('XSRF-TOKEN='.length));
}

/**
 * v4.0/W3.2 — adapter hook over `@ai-sdk/react`'s `useChat()` that
 * targets AskMyDocs's W3.1 SSE streaming endpoint.
 *
 * Wraps three concerns into one ergonomic hook:
 *   1. Custom transport pointing at
 *      `POST /conversations/{conversationId}/messages/stream`
 *      (the W3.1 controller delivered in PR #87).
 *   2. `prepareSendMessagesRequest` that injects AskMyDocs's
 *      per-turn `filters` into the request body — the SDK's default
 *      transport sends `messages` only; we need `{ content, filters }`
 *      to match the streaming controller's validation contract
 *      (the BE rules mirror the synchronous `MessageController`
 *      route — see `MessageStreamController::store()`).
 *   3. Optional `initialMessages` seeded from the TanStack Query
 *      `['messages', conversationId]` cache so the SDK doesn't
 *      forget thread history when the component mounts after the
 *      user has navigated away and back.
 *
 * NOT a drop-in for `useChatMutation()`. The migration from the
 * existing `useChatMutation` + `useQuery(['messages', :id])` shape
 * to `useChat()` is a separate (larger) commit per PLAN §5; this
 * hook is the typed-and-tested foundation that lets that commit
 * stay focused on component changes.
 *
 * Returns the unmodified `UseChatHelpers<UIMessage>` so consumers
 * have access to the full SDK surface (`messages`, `sendMessage`,
 * `status`, `error`, `stop`, `regenerate`, etc.). `mapStatusToDataState()`
 * (in this folder) is the dedicated adapter that converts the SDK
 * `status` enum to the AskMyDocs `data-state` attribute.
 */

export interface UseChatStreamOptions {
    /**
     * The conversation id this hook drives. When `null`, the hook
     * still mounts (with an inert transport — `sendMessage()` calls
     * route to a path that returns 404 because no conversation
     * exists). Callers should defer message sends until they have a
     * non-null id, mirroring the pre-W3.2 `Composer.send()` behaviour.
     */
    conversationId: number | null;

    /**
     * Per-turn retrieval filters applied to RAG search. Threaded into
     * the request body via `prepareSendMessagesRequest`. Reads from a
     * ref on each `sendMessage()` call so updates between turns don't
     * require remounting the hook.
     */
    filters: FilterState;

    /**
     * Existing message history (typically from the TanStack Query
     * cache `['messages', conversationId]`). Mapped to the SDK's
     * `UIMessage` shape via `appMessageToUiMessage()`.
     *
     * Optional: when omitted the SDK starts with an empty thread.
     */
    initialMessages?: AppMessage[];

    /**
     * Optional onFinish callback. Fires after the stream's terminal
     * `finish` event — the same hook the persistence/dedupe path
     * needs in the bigger refactor commit. Passed through unchanged.
     */
    onFinish?: () => void;

    /**
     * Optional onError callback. Fires when the SDK reports a
     * transport / parse / network error. Passed through unchanged.
     */
    onError?: (error: Error) => void;
}

/**
 * Build the streaming endpoint URL for a conversation. Exported so
 * the test suite can assert the URL shape without re-reading
 * production routes/web.php.
 */
export function buildStreamEndpoint(conversationId: number | null): string {
    // null → a sentinel that 404s. Used so the hook can mount before
    // a conversation exists; callers must avoid sendMessage() calls
    // until conversationId is set.
    if (conversationId === null) {
        return '/conversations/0/messages/stream';
    }
    return `/conversations/${conversationId}/messages/stream`;
}

/**
 * Map an AskMyDocs `Message` (BE shape with `metadata`) to the
 * Vercel SDK's `UIMessage` shape. Each AskMyDocs message becomes a
 * single-part `text` message whose `parts` array is `[{ type: 'text',
 * text: content }]`. Citations / refusal / confidence don't round-trip
 * through this conversion — they live in `metadata` and the
 * MessageBubble component reads them directly off the persisted
 * message via `useQuery` after `onFinish` reconciles. PLAN §5.4
 * notes the migration of those sub-components is a separate
 * follow-up commit.
 *
 * The `id` is stringified because the SDK uses string ids
 * everywhere; the BE issues numeric ids and we keep both forms via
 * the `data-testid="chat-message-{id}"` shape (which uses the
 * stringified value already in the existing DOM contract).
 */
export function appMessageToUiMessage(m: AppMessage): UIMessage {
    // Return a fully-shaped UIMessage WITHOUT a type assertion so
    // TypeScript catches drift when the SDK adds / renames required
    // fields. The SDK's UIMessage shape (per `ai` v6) needs `id`,
    // `role`, and `parts`. The earlier draft of this comment claimed
    // we also kept a top-level `content` field for SDK-major
    // backwards compat — that was incorrect (the older shape was
    // already replaced by the parts-only contract in `ai` v6). The
    // text payload lives EXCLUSIVELY in `parts[0]` for the v6 SDK.
    return {
        id: String(m.id),
        role: m.role,
        parts: [{ type: 'text', text: m.content }],
    };
}

export function useChatStream(options: UseChatStreamOptions): UseChatHelpers<UIMessage> {
    const { conversationId, filters, initialMessages, onFinish, onError } = options;

    // `filters` flows into `prepareSendMessagesRequest` through a ref
    // so the transport stays stable across filter changes. Closing
    // OVER the value (the previous implementation) froze the filter
    // snapshot at the render that created the transport — Composer
    // updates filters via `setFilters(prev => ({ ...prev, ... }))`,
    // so subsequent sends would have used stale data. The ref +
    // useEffect-on-render pattern lets us read the latest snapshot
    // inside the request preparer without re-creating the transport
    // (which would tear down in-flight streams).
    const filtersRef = useRef(filters);
    useEffect(() => {
        filtersRef.current = filters;
    }, [filters]);

    // Prime the XSRF-TOKEN cookie at hook mount so the first
    // `sendMessage()` doesn't 419. Idempotent (`csrfPrimed` flag
    // inside `ensureCsrfCookie()`); cheap repeat call. We don't
    // await the result here — the SDK's first POST kicks off when
    // the user submits, which is gated by user interaction (typing
    // + clicking send), giving the Promise plenty of time to land.
    useEffect(() => {
        void ensureCsrfCookie();
    }, []);

    // Memoise the transport so React doesn't tear down + rebuild it
    // on every render. It depends only on the conversation id; the
    // filters mutate per-turn and feed in via the ref above.
    const transport = useMemo(() => {
        const apiUrl = buildStreamEndpoint(conversationId);
        return new DefaultChatTransport<UIMessage>({
            api: apiUrl,
            // Cookie-based session auth — the SPA already runs under
            // /sanctum/csrf-cookie + session cookie. Setting
            // credentials: 'same-origin' is the default for fetch but
            // we declare it explicitly so a future fetch-polyfill or
            // proxy environment doesn't silently drop it.
            credentials: 'same-origin',
            // The SDK's default request body is `{ id, messages, ... }`;
            // the W3.1 streaming controller wants `{ content, filters? }`
            // (matching MessageController). Re-shape via
            // prepareSendMessagesRequest, reading the LAST message's
            // text content as the `content` field. This tracks the
            // synchronous `chatApi.sendMessage()` payload byte-for-byte.
            //
            // We also thread the X-XSRF-TOKEN header through here:
            // the SDK's DefaultChatTransport uses `fetch` (NOT
            // axios), so it doesn't get the automatic cookie→header
            // forwarding the shared axios instance has. Reading the
            // current cookie inside `prepareSendMessagesRequest`
            // (instead of capturing at memo time) means a refreshed
            // session cookie after a 419-and-retry is picked up
            // without remounting the hook. Sanctum also expects
            // `X-Requested-With: XMLHttpRequest` to recognize the
            // call as an SPA request via
            // `EnsureFrontendRequestsAreStateful` — match the axios
            // instance's default header set.
            prepareSendMessagesRequest: ({ messages }) => {
                const last = messages[messages.length - 1];
                const content = last?.parts
                    ?.filter((p): p is { type: 'text'; text: string } => p.type === 'text')
                    .map((p) => p.text)
                    .join('') ?? '';
                const liveFilters = filtersRef.current;
                const body: { content: string; filters?: FilterState } = { content };
                if (liveFilters && !isFilterStateEmpty(liveFilters)) {
                    body.filters = liveFilters;
                }
                const headers: Record<string, string> = {
                    Accept: 'text/event-stream',
                    'X-Requested-With': 'XMLHttpRequest',
                };
                const xsrf = readXsrfCookie();
                if (xsrf !== null) {
                    headers['X-XSRF-TOKEN'] = xsrf;
                }
                return { body, headers };
            },
        });
    }, [conversationId]);

    const initial = useMemo(
        () => initialMessages?.map(appMessageToUiMessage) ?? [],
        [initialMessages],
    );

    return sdkUseChat<UIMessage>({
        // Stable id per conversation so the SDK keys its internal state
        // map correctly. Switching conversations remounts the hook with
        // a different id, which forces fresh state.
        id: conversationId === null ? 'pending' : `conv-${conversationId}`,
        // `messages` (NOT `initialMessages`) is the v3 SDK API per
        // `ChatInit<UI_MESSAGE>.messages?: UI_MESSAGE[]` — the v2
        // option name `initialMessages` was renamed when v3 unified
        // initial state with the live message buffer (`useChat()`
        // seeds the internal store from `messages` and the user
        // controls subsequent state via `sendMessage()` / etc.).
        // Our hook's option API exposes `initialMessages` as a more
        // familiar React-developer name and translates here — see
        // the `UseChatStreamOptions.initialMessages` JSDoc above.
        messages: initial,
        transport,
        onFinish,
        onError,
    });
}
