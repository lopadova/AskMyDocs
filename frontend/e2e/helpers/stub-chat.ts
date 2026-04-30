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
 * `scripts/verify-e2e-real-data.sh` matches
 * `/conversations/[^"]*/messages` — un-anchored at the right edge,
 * so it already covers BOTH the synchronous `…/messages` URL the
 * helper targets today AND the W3.2 `…/messages/stream` URL the
 * helper-switch commit will target. No allowlist edit is required
 * when the URL flips; the verification step stays green.
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

    await page.route('**/conversations/*/messages', async (route) => {
        const method = route.request().method();

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
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(options.assistant),
        });
    });
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
