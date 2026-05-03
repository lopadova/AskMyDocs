import type { Page } from '@playwright/test';

/**
 * v4.0/W3.2 — shared chat-network stubbing helpers used by every
 * `chat*.spec.ts` file in the suite.
 *
 * Why this exists: per `feedback_w3_vercel_test_rigor` §2 + the W3
 * design doc §7.2, the W3.2 FE migration to `@ai-sdk/react` switches
 * the SPA from `POST /conversations/{id}/messages` (synchronous JSON)
 * to `POST /conversations/{id}/messages/stream` (SSE protocol per
 * W3.1). If each spec file inlines its own request-stub block against
 * the synchronous endpoint, a future change to the streaming endpoint
 * would force per-spec edits — violating the zero-edit gate Lorenzo
 * locked in for design fidelity.
 *
 * The contract here is "stub the chat-completion call site" without
 * naming the URL/protocol. Today the helper fulfills the synchronous
 * JSON shape against `POST /conversations/{id}/messages`. When the SPA
 * migrates to the streaming endpoint, ONLY this file changes — every
 * spec call site stays byte-identical and continues to pass.
 *
 * NOTE(R13): the `EXTERNAL_PROXY_PATTERNS` entry in
 * `scripts/verify-e2e-real-data.sh` matches the conversations-
 * messages route shape with no anchor at the right edge, so it
 * already covers BOTH the synchronous form (path ends with the
 * `messages` segment) AND the W3.2 streaming form (same path with
 * a trailing `/stream` segment). No allowlist edit is required
 * when the helper-switch commit flips the URL; the verification
 * step stays green.
 *
 * Why this NOTE doesn't quote the regex literal: a `*` followed
 * by `/` inside a JSDoc backtick code-span terminates the
 * comment block prematurely (the comment lexer is not
 * code-span-aware). Caused a CI Playwright parse failure on
 * commit d05af7a; do not re-introduce.
 *
 * @see feedback_w3_vercel_test_rigor (memory)
 * @see docs/v4-platform/PLAN-W3-vercel-chat-migration.md §7.2
 */

/**
 * Subset of the `Message` shape the FE expects from the synchronous
 * chat endpoint. Mirrors `app/Models/Message.php` fillable + the
 * `metadata` blob shape `MessageController::store()` returns. Stays
 * loose (`Record<string, unknown>` for nested objects) so test
 * payloads can include only the fields each scenario actually
 * asserts on.
 */
export interface StubChatMessage {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    metadata: Record<string, unknown> | null;
    rating: 'positive' | 'negative' | null;
    created_at: string;
    confidence?: number | null;
    refusal_reason?: 'no_relevant_context' | 'llm_self_refusal' | null;
}

export interface StubChatOptions {
    /**
     * The assistant message returned on `POST /conversations/{id}/messages`.
     */
    assistant: StubChatMessage;

    /**
     * The full message list returned on `GET /conversations/{id}/messages`
     * (the refetch `useChatMutation` triggers via `invalidateQueries`
     * on success). Defaults to `[assistant]` — sufficient for tests
     * that don't assert on the refetch result. Tests that render the
     * user message in the thread (e.g. wikilink hover) MUST pass
     * `[userMessage, assistantMessage]` explicitly so the GET stub
     * keeps the user turn visible after the optimistic-mutation
     * dedupe runs.
     */
    list?: StubChatMessage[];

    /**
     * Invoked with the parsed POST body (`{ content, filters? }`)
     * before the response is fulfilled. Used by spec files that
     * assert on the request shape (e.g. chat-filters.spec.ts
     * verifying that filter chips thread into the POST payload —
     * R20 contract assertion).
     */
    onPost?: (body: { content?: string; filters?: Record<string, unknown> }) => void;
}

/**
 * Stub the chat-completion request/response cycle for a single test.
 *
 * - POST `/conversations/{id}/messages` → returns `options.assistant`
 *   as the assistant Message JSON.
 * - GET `/conversations/{id}/messages` → returns `options.list`
 *   (defaults to `[assistant]`) as the message thread.
 * - Any other method → `route.fallback()` (real backend).
 *
 * The stub registers via Playwright's request-interception API and
 * stays active for the remainder of the test. Callers DO NOT need to
 * unroute() — Playwright tears down route handlers between tests
 * automatically.
 */
export async function stubChatAssistantReply(page: Page, options: StubChatOptions): Promise<void> {
    const list = options.list ?? [options.assistant];

    // Track POST observation per route handler so the GET refetch
    // returns `[]` BEFORE the user sends a message, and the seeded
    // `list` AFTER. Without this gate, the chat thread would render
    // the stubbed assistant message immediately on page load — the
    // user could `data-state="ready"` without the send round-trip
    // ever firing, weakening the E2E assertion that the send flow
    // actually executes (R16: tests must exercise the behaviour they
    // claim). Closure-scoped boolean is fine because `page.route` is
    // re-registered per-test (Playwright tears down route handlers
    // between tests automatically).
    let postObserved = false;

    // The W3.2 swap commit moved the FE chat send from the
    // synchronous JSON endpoint (`POST /conversations/{id}/messages`)
    // to the SSE streaming endpoint
    // (`POST /conversations/{id}/messages/stream`). Two separate
    // route registrations match the EXACT URL shapes we care about,
    // dispatching to the same async handler; sub-paths like
    // `/messages/{id}/feedback` fall through to the real backend
    // automatically per R13 because no route matches them.
    //
    // The stub responds to:
    //   - GET  /conversations/{id}/messages        → JSON history list
    //   - POST /conversations/{id}/messages/stream → SSE event stream
    //   - POST /conversations/{id}/messages        → JSON (legacy fallback,
    //     kept so any non-migrated component still gets a
    //     deterministic reply during the cross-component transition)
    //
    // Why two registrations rather than one wide glob: a `**`
    // segment-spanning pattern would match every sub-path under
    // `/messages/` (rating, feedback, regenerate, etc.) and the
    // handler would need a manual URL-regex bouncer. Two narrow
    // patterns keep the routing intent visible and avoid the
    // bouncer entirely.
    await page.route('**/conversations/*/messages', handleChat);
    await page.route('**/conversations/*/messages/stream', handleChat);

    async function handleChat(route: Parameters<Parameters<Page['route']>[1]>[0]): Promise<void> {
        const url = route.request().url();
        const method = route.request().method();
        const path = new URL(url).pathname;
        const isStream = path.endsWith('/stream');

        if (method === 'GET') {
            const body = postObserved ? list : [];
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(body),
            });
            return;
        }

        if (method !== 'POST') {
            await route.fallback();
            return;
        }

        if (options.onPost) {
            const body = route.request().postDataJSON() as {
                content?: string;
                filters?: Record<string, unknown>;
            };
            options.onPost(body);
        }

        postObserved = true;

        // Streaming endpoint → emit SSE protocol in the SDK v6
        // `UIMessageChunk` shape (start / text-start /
        // text-delta(id+delta) / text-end / source-url / data-* /
        // finish). PR #90 aligned the BE wire format to the same
        // SDK v6 shape, so every chunk type / field name matches
        // the production stream. Minor metadata still differs
        // intentionally (the stub seeds a `messageId` on `start`
        // for testid stability and uses `/kb/...` URLs for fixture
        // citations whereas the production BE emits its own
        // canonical URLs) — those differences don't affect the
        // adapters under test.
        //
        // Single-shot fulfill with the whole stream body works
        // because the SDK's parser handles concatenated chunks in
        // one response — chat*.spec.ts tests assert on the final
        // DOM state, not on mid-stream timing.
        if (isStream) {
            const sseBody = buildSseStreamBody(options.assistant);
            await route.fulfill({
                status: 200,
                contentType: 'text/event-stream',
                body: sseBody,
                headers: {
                    'Cache-Control': 'no-cache',
                    Connection: 'keep-alive',
                },
            });
            return;
        }

        // Synchronous endpoint → legacy JSON shape (untouched).
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(options.assistant),
        });
    }
}

/**
 * Compose an SSE response body from a `StubChatMessage` in the
 * `@ai-sdk/react` v6 UI Message Stream Protocol shape (see
 * `node_modules/ai/dist/index.d.mts` `UIMessageChunk`).
 *
 * The chunk types and field names match what the production BE
 * (`MessageStreamController::store()`) emits since PR #90 aligned
 * the wire format to SDK v6 (`UIMessageChunk` discriminator union).
 * Per-field metadata still differs intentionally — the stub seeds a
 * `messageId` on the `start` chunk and uses `/kb/{sourceId}` for
 * fixture citation URLs, whereas the production BE produces its own
 * canonical URL strings. Those differences are testid-stability
 * details that don't affect the FE adapters under test.
 *
 * The chunk sequence:
 *   1. `start` — opens the assistant message with messageId
 *   2. `source-url` × N — citations (canonical citations without
 *      a public URL fall back to `/`-anchored placeholder so the
 *      SDK's required `url` field is satisfied)
 *   3. Either:
 *      a) `data-refusal` + `data-confidence` (refusal path)
 *      b) `text-start` → `text-delta` → `text-end` + optional
 *         `data-confidence` (happy path)
 *   4. `finish` — terminal
 *
 * Frame format is `data: {json}\n\n` per SSE framing.
 */
function buildSseStreamBody(assistant: StubChatMessage): string {
    const lines: string[] = [];
    const messageId = `stub-msg-${assistant.id}`;
    const textPartId = `${messageId}-text`;

    const emit = (chunk: object): void => {
        lines.push(`data: ${JSON.stringify(chunk)}\n\n`);
    };

    // 1. Open the message envelope.
    emit({ type: 'start', messageId });

    // 2. Source citations BEFORE text-delta (the FE's CitationsPopover
    //    renders the chips as the events arrive). The SDK's
    //    `source-url` shape requires `sourceId` + `url`; canonical
    //    citations without a public URL get a `/`-anchored placeholder
    //    so the parser doesn't reject the chunk.
    const meta = assistant.metadata as Record<string, unknown> | null;
    const citations = (meta?.citations as Array<{
        document_id?: number | null;
        title?: string;
        url?: string | null;
    }> | undefined) ?? [];
    for (const c of citations) {
        const sourceId = c.document_id != null ? `doc-${c.document_id}` : 'doc-unknown';
        emit({
            type: 'source-url',
            sourceId,
            url: c.url ?? `/kb/${sourceId}`,
            title: c.title ?? 'Untitled',
        });
    }

    if (assistant.refusal_reason != null) {
        // 3a. Refusal path. BE emits data-refusal + data-confidence
        //     BEFORE the assistant text. The SDK's `data-${name}`
        //     custom-part shape wraps the payload under `.data`.
        emit({
            type: 'data-refusal',
            data: {
                reason: assistant.refusal_reason,
                body: assistant.content,
            },
        });
        emit({
            type: 'data-confidence',
            data: { confidence: 0, tier: 'refused' },
        });
    } else {
        // 3b. Happy path: text-start → text-delta → text-end. The
        //     SDK accumulates `delta` strings between matching
        //     id'd text-start / text-end pairs into the assistant
        //     message's text content.
        emit({ type: 'text-start', id: textPartId });
        emit({ type: 'text-delta', id: textPartId, delta: assistant.content });
        emit({ type: 'text-end', id: textPartId });

        if (typeof assistant.confidence === 'number') {
            const tier = assistant.confidence >= 80
                ? 'high'
                : assistant.confidence >= 50
                    ? 'moderate'
                    : 'low';
            emit({
                type: 'data-confidence',
                data: { confidence: assistant.confidence, tier },
            });
        }
    }

    // 4. Terminal finish chunk. The SDK v6 `UIMessageChunk.finish`
    // type's `finishReason` union doesn't include `'refusal'`
    // (the BE has its own broader vocabulary), so we always emit
    // `'stop'` here — refusal vs grounded paths differ in the
    // `data-refusal` chunk presence above, not in the finish marker.
    emit({
        type: 'finish',
        finishReason: 'stop',
    });

    return lines.join('');
}

/**
 * Stub the wikilink resolver to fail with the given status. Used by
 * the "wikilink resolver 500 degrades gracefully" scenario in
 * chat.spec.ts (R13: failure injection). When this helper is wired,
 * any `GET /api/kb/resolve-wikilink?...` returns the supplied status
 * response, the React Query fetcher rethrows, and the UI renders the
 * `wikilink-preview-error` state instead of a resolved preview.
 */
export async function stubWikilinkResolveError(
    page: Page,
    status: number = 500,
): Promise<void> {
    /* R13: failure injection — real path tested in chat.spec.ts "wikilink hover fetches and shows the preview card". */
    await page.route('**/api/kb/resolve-wikilink**', (route) => route.fulfill({ status }));
}

/**
 * Helper for building a minimal-shape assistant message payload with
 * sensible defaults. Spec files compose their per-scenario message by
 * spreading this output and overriding the fields they care about
 * (id, content, metadata.citations, refusal_reason, etc.). Keeps
 * fixtures terse without sacrificing the explicit shape contract.
 */
export function buildAssistantMessage(
    overrides: Omit<Partial<StubChatMessage>, 'role'> & { id: number; content: string },
): StubChatMessage {
    // `role` deliberately excluded from the override surface (Omit
    // above) AND assigned AFTER the spread so the helper-name
    // invariant ("assistant message") cannot be silently violated by
    // a caller that passes `role: 'user'`. A user-message helper
    // exists separately (buildUserMessage()).
    return {
        metadata: { provider: 'mock', model: 'mock', citations: [] },
        rating: null,
        created_at: new Date().toISOString(),
        ...overrides,
        role: 'assistant',
    };
}

/**
 * Helper for building a minimal-shape user message payload — used in
 * scenarios that need both turns visible in the GET list (wikilink
 * hover etc.). Same defaults pattern as `buildAssistantMessage()`.
 */
export function buildUserMessage(
    overrides: Omit<Partial<StubChatMessage>, 'role'> & { id: number; content: string },
): StubChatMessage {
    // Same role-override guard as buildAssistantMessage(): role is
    // applied AFTER the spread and excluded from the override surface
    // so the helper invariant ("user message") holds even when a
    // caller spreads a different StubChatMessage as overrides.
    return {
        metadata: null,
        rating: null,
        created_at: new Date().toISOString(),
        ...overrides,
        role: 'user',
    };
}
