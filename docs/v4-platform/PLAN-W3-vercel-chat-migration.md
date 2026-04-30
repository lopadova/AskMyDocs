# v4.0 W3 — Vercel AI SDK UI chat migration design

**Status:** DRAFT — design doc per `feedback_vercel_chat_migration_design_fidelity` ("design doc completo prima del codice").

**Constraint:** design fidelity 1:1 ASSOLUTA. The migrated chat must be visually + behaviourally indistinguishable from the current chat to a returning user.

**Scope:** the W3 deliverable per `project_v40_week_sequence` is "Vercel AI SDK UI full migration of chat (design fidelity assoluta)". Full migration of `frontend/src/features/chat/` to use `@ai-sdk/react` + `ai` while preserving every existing feature, testid, accessibility property, and pixel-level visual.

---

## 1. Why this migration

The current chat is a fully custom React implementation: ~30 components and hooks under `frontend/src/features/chat/`, hand-rolled state via TanStack Query + Zustand, hand-rolled optimistic mutations, hand-rolled streaming-equivalent (we don't actually stream today — every assistant reply lands as one POST response).

The Vercel AI SDK UI (`@ai-sdk/react`) provides:

- `useChat()` — message-list state + input + streaming + status as one cohesive primitive
- First-class streaming (server-sent events) with token-level rendering
- Built-in support for tool calls, sources, attachments, parts (multi-modal content)
- A clean separation between transport (`DefaultChatTransport` or custom) and UI

Adopting the SDK gives us:

1. **Streaming for free** — assistant reply renders as tokens arrive instead of as one block after 3 s
2. **Tool calls + sources as first-class** — citations + reasoning steps map onto SDK `parts` instead of being smuggled inside `metadata`
3. **Reduced custom code** — the optimistic-mutation dedupe (`use-chat-mutation.ts`), the message-list TanStack Query (`MessageThread.tsx`), and large parts of the composer become SDK-managed
4. **Future-proofing** — when the SDK adds new capabilities (multi-modal, agentic, etc.), AskMyDocs gets them automatically

The cost: a substantial refactor of the chat feature and a backend contract change (POST → SSE streaming endpoint).

---

## 2. Current chat UI inventory (W3 baseline)

Compiled from explore agent `2026-04-30 W3 mapping`.

### 2.1 Layout shell
- `frontend/src/routes/index.tsx:133-142` — flat `chatRoute` + `chatConversationRoute` siblings
- `frontend/src/features/chat/ChatView.tsx` (component spans the full file, ~150 lines) — three-column layout (sidebar / thread+composer / future graph)
- URL is the source of truth for `activeConversationId` (R11 §5)

### 2.2 Composer (`Composer.tsx:39-320`)
- Textarea (Enter-to-send, Shift+Enter newline, mention detection, draft persisted to Zustand)
- `<FilterBar>` above textarea — chips + `+ Filter` trigger → `<FilterPickerPopover>` with 7 tabs (project / tag / source / canonical / folder / date / language)
- `<MentionPopover>` triggered by `@` keystroke; fetches `/api/kb/documents/search`
- `<FilterPresetsDropdown>` next to FilterBar — CRUD against `/api/chat-filter-presets`
- Send / Attach buttons; client-side required-field check; server-error surface

### 2.3 Thread (`MessageThread.tsx:22-110`)
- Container with `data-state ∈ {idle, loading, ready, empty, error}` + `aria-live="polite"`
- Auto-scroll on new messages
- Empty state with three suggested prompts (`EmptyThread()`)

### 2.4 Message rendering (`MessageBubble.tsx`)
- User messages: right-aligned bubble
- Assistant messages: full-width with sub-components:
  - `<ThinkingTrace>` (for `metadata.reasoning_steps`)
  - Markdown body (with `<WikilinkHover>` overlay on `[[slug]]`)
  - `<RefusalNotice>` when `refusal_reason !== null`
  - `<CitationsPopover>` for `metadata.citations`
  - `<ConfidenceBadge>` for confidence/refusal grounding
  - `<MessageActions>` + `<FeedbackButtons>` row

### 2.5 Mutation flow (`use-chat-mutation.ts:25-91`)
- TanStack `useMutation` with optimistic-add + rollback on error
- `onSuccess` dedupes by ID (R25 — both optimistic-id and server-id filter) before append
- Invalidates `['conversations']` and `['messages', conversationId]` after success

### 2.6 State management
- **Server state**: TanStack Query — `['conversations']`, `['messages', :id]`, `['wikilink', project, slug]`, `['chat-filter-presets']`
- **UI state**: Zustand (`chat.store.ts:27-40`) — `activeConversationId`, `draft`, `isListening`, `showGraph`, `sidebarOpen`
- **Composer-local**: `filters`, `mentionQuery`, `mentionAnchorRef`, `focused`, `localError`, `docLabelMap`

### 2.7 Backend contract (current)
- `POST /conversations/{id}/messages` { content, filters? } → `Message` (synchronous, full JSON)
- `GET /conversations/{id}/messages` → `Message[]`
- `POST /.../feedback` { rating } → `{ rating }`
- `GET /api/kb/resolve-wikilink` (silent fallback to plain text on 404/403)
- `GET /api/kb/documents/search` (mention autocomplete)

### 2.8 TestID surface (60+ contracts)
- Core: `chat-view`, `chat-thread`, `chat-message-{id}`, `chat-suggested-prompt-{0,1,2}`
- Composer: `chat-composer-input`, `chat-composer-send`, `message-error`
- Filters: `chat-filter-bar*`, `filter-popover*`, `filter-tab-{dim}`, `filter-{dim}-option-{id}`
- Mentions: `mention-popover`, `mention-option-{id}`
- Presets: `chat-filter-presets-*` (12 testids)
- Citations: `chat-citations`, `chat-citation-{idx}`, `chat-citations-popover`
- Refusal: `refusal-notice`, `refusal-notice-body`, `refusal-notice-hint`
- Confidence: `confidence-badge` (`data-state={tier}`)
- Feedback: `chat-feedback`, `chat-feedback-up/down/error`
- Wikilinks: `wikilink-{slug}`

---

## 3. Vercel AI SDK UI capability map

### 3.1 What the SDK provides

`@ai-sdk/react` exports:

- `useChat({ id, initialMessages, api, transport, onFinish, onError, ... })` — the core hook. The options shown here are non-exhaustive: later sections of this plan rely on `id` (per-conversation state) and `initialMessages` (hydrate from server-fetched thread). Returns `{ messages, input, handleInputChange, handleSubmit, status, error, stop, reload, append }`. Manages message list, input field, streaming, and SSE plumbing.
- `Message` type with `parts: MessagePart[]` where `MessagePart` covers text, tool-call, tool-result, source, file. Citations + reasoning naturally model as parts.
- `DefaultChatTransport` for `/api/chat`-style streaming endpoints; custom transport for non-standard routes.
- Hooks for tool-call rendering (`useChatToolCallStreamPart`), inferring sources, etc.

### 3.2 What the SDK does NOT provide

- Filter bar / mention popover / saved presets — these are AskMyDocs-specific and stay custom.
- Wikilink overlay on rendered Markdown — stays custom.
- Refusal notice / confidence badge — these map onto custom `parts` but the rendering stays custom.
- 3-column shell + sidebar + URL-sync for `activeConversationId` — stays custom.
- Optimistic-mutation dedupe by ID — replaced by SDK's own optimistic-message handling.

### 3.3 What changes structurally

| Layer | Current | After migration |
|---|---|---|
| Message-list state | TanStack Query `['messages', :id]` + Zustand draft | `useChat({ id: conversationId, initialMessages })` |
| Optimistic update | `useChatMutation` with manual rollback + dedupe | SDK-managed (optimistic id + server ack via SSE) |
| Composer state | Composer-local + Zustand | `useChat`'s `input` / `handleInputChange` / `handleSubmit` (with custom prepareSendMessagesRequest for filters) |
| Backend contract | POST → JSON | POST → SSE stream |
| Citations / reasoning | inside `metadata`, rendered post-stream | first-class `parts` (`source`, `reasoning`) |
| Refusal | top-level `refusal_reason` field | custom `data-refusal` part |
| Confidence | top-level `confidence` field | custom `data-confidence` part |

---

## 4. Backend streaming requirement (DECISION POINT)

The current backend (`MessageController` → `KbChatController` → `AiManager::chat()`) returns the full assistant reply as one synchronous JSON response after the AI provider finishes. Vercel AI SDK UI is designed around streaming.

### 4.1 Option A — keep synchronous backend, fake-stream on client

Use the SDK with `DefaultChatTransport` pointed at the existing POST endpoint, BUT wrap the response in a single SSE event so the SDK perceives it as a (very short) stream.

- **Pro:** zero backend changes; W3 stays a frontend-only PR.
- **Pro:** existing AiManager streaming-or-not behaviour preserved (some providers don't stream).
- **Con:** users still see the same 3 s wait → full response, no token streaming benefit. Defeats half the "why Vercel SDK" rationale.
- **Con:** misuse of SDK primitives — the SDK assumes SSE, faking it adds friction.

### 4.2 Option B — add a streaming SSE endpoint, gate the new chat behind a flag

Implement `POST /conversations/{id}/messages/stream` returning SSE per the AI SDK protocol. Provider-side: every `AiProviderInterface` adds `chatStream(...)` that emits chunks; falls back to one-chunk emit when the provider doesn't support streaming.

- **Pro:** real streaming; tokens render as they arrive; matches modern chat UX.
- **Pro:** clean separation — old endpoint stays for the legacy chat; new endpoint for the migrated chat.
- **Con:** backend scope creep — adds 1-2 days of work to the W3 budget.
- **Con:** every provider adapter needs a streaming variant or fallback.

### 4.3 Option C — backend stays synchronous, SDK uses `experimental_attachments`-style transport

Configure `useChat` with a custom transport that wraps the existing JSON response into the SDK's expected stream protocol *on the client*. Conceptually similar to A but with the wrapping layer in TypeScript not Laravel.

- **Pro:** zero backend changes.
- **Pro:** SDK perceives standard protocol (no faking inside the route).
- **Con:** still no token streaming.

### 4.4 Recommendation

**Option B** is the right structural choice for v4.0 (Lorenzo's W3 vision is "full migration"; staying synchronous trades 50% of the value). Budget impact: +1 day for the streaming endpoint, +0.5 day per provider for the streaming adapter (Regolo + OpenAI to start; others can fall back).

**OPEN QUESTION FOR LORENZO:** confirm Option B is in scope, or pivot to Option A and treat token streaming as a W3.2 follow-up.

---

## 5. Component-by-component migration map

### 5.1 ChatView shell — KEEP custom, adapt internals
- Layout, sidebar, URL-sync, Zustand integration → unchanged.
- Replace `<MessageThread>` and `<Composer>` internals (see below) with SDK-driven equivalents but keep the outer testids (`chat-view`, etc.).

### 5.2 MessageThread — REPLACE with `useChat` consumer
- Drop the TanStack `['messages', :id]` query.
- New: `const { messages, status } = useChat({ id: conversationId, initialMessages: prefetchedMessages });`
- `data-state` derives from `status` ∈ `submitted | streaming | ready | error` plus `messages.length === 0` for empty.
- Auto-scroll logic moves to a `useEffect` watching `messages.length` and `status`.
- TestID `chat-thread` + `chat-message-{id}` + `chat-thread-empty` + `chat-thread-error` preserved as-is (rendered on the same DOM nodes in the new flow).

### 5.3 Composer — REPLACE input/send with `useChat`'s handlers, KEEP filter/mention/preset overlays
- `input` / `handleInputChange` / `handleSubmit` from `useChat` replace local textarea state + `chatApi.sendMessage`.
- Filters / mentions / presets stay custom (no SDK equivalent). `handleSubmit` accepts a `body` parameter — pass `filters` there via `prepareSendMessagesRequest`.
- TestIDs `chat-composer-input`, `chat-composer-send`, `message-error`, `chat-composer-error` preserved.

### 5.4 MessageBubble — KEEP custom, switch over `message.parts` instead of `message.metadata`
- Each part renders to the corresponding sub-component:
  - `text` → Markdown body (with `<WikilinkHover>` overlay)
  - `source` → `<CitationsPopover>` (one popover per `source-url` group)
  - `reasoning` → `<ThinkingTrace>` (when `metadata.reasoning_steps` was the source)
  - custom `data-refusal` → `<RefusalNotice>`
  - custom `data-confidence` → `<ConfidenceBadge>`
- TestIDs `chat-message-{id}` + `data-role` preserved on the outer `<article>`.

### 5.5 Citations / WikilinkHover / Refusal / Confidence / Feedback / Thinking — UNCHANGED visuals
- Each component's render output stays bit-for-bit identical to current.
- Inputs change: `citations` array now comes from `message.parts.filter(p => p.type === 'source')` instead of `message.metadata.citations`. A small adapter inside `MessageBubble` keeps the sub-components agnostic.

### 5.6 use-chat-mutation.ts — DELETE
- Optimistic add + rollback + dedupe is now SDK-managed.
- Manual `invalidateQueries(['conversations'])` after a new conversation is created moves to a `useChat({ onFinish })` callback.

### 5.7 chat.store.ts (Zustand) — UNCHANGED
- `activeConversationId`, `draft`, `isListening`, `showGraph`, `sidebarOpen` all stay.
- The composer's `input` is SDK-managed during a turn but `draft` (Zustand) persists across page navigations / unmounts. Hydrate `useChat`'s `input` from `draft` on mount.

### 5.8 chat.api.ts — TRIM
- `sendMessage`, `listMessages` removed (SDK handles these).
- `listConversations`, `createConversation`, `rateMessage`, `resolveWikilink`, `searchDocuments`, filter-presets CRUD all stay.

### 5.9 FilterBar / MentionPopover / FilterPresetsDropdown — UNCHANGED
- Pure UI components, no SDK touch-points. They feed `filters` into `handleSubmit` via the body parameter.

---

## 6. Backend changes (Option B)

### 6.1 New route
```
POST /conversations/{id}/messages/stream  → SSE
```
Same auth + filter contract as the synchronous route. Response headers:

```
Content-Type: text/event-stream
Cache-Control: no-cache, no-transform
X-Accel-Buffering: no              # disable nginx buffering on prod
Connection: keep-alive
```

Each SSE message is `data: <json-payload>\n\n`. Payload is a discriminated union on `type`:

```
data: {"type":"source","sourceId":"doc-101","title":"Remote work policy","url":"/app/admin/kb/hr-portal/remote-work-policy","origin":"primary"}

data: {"type":"text-delta","textDelta":"The remote work stipend"}

data: {"type":"text-delta","textDelta":" applies to full-time employees"}

data: {"type":"data-confidence","confidence":82,"tier":"high"}

data: {"type":"text-delta","textDelta":" after 90 days."}

data: {"type":"finish","finishReason":"stop","usage":{"promptTokens":1234,"completionTokens":56}}
```

Refusal stream variant (no `text-delta` events):

```
data: {"type":"data-refusal","reason":"no_relevant_context","body":"...","hint":"..."}

data: {"type":"data-confidence","confidence":null,"tier":"refused"}

data: {"type":"finish","finishReason":"refusal","usage":{"promptTokens":1234,"completionTokens":0}}
```

Wire-format invariants:
- Each event MUST end with `\n\n` (SSE framing).
- `data:` payload MUST be valid JSON (no comment lines, no multi-line `data:` continuations).
- `text-delta` order is significant; concatenating all `textDelta` strings in order MUST equal the full assistant text.
- `source` events MUST emit before the first `text-delta` (so the citations strip can render before the answer).
- Exactly one `finish` event terminates the stream; no events after it.

PHPUnit `MessageStreamControllerTest` (§7.5) ships a recorded fixture file `tests/fixtures/sse/happy-path.txt` that the protocol-drift test (§7.5 #8) compares byte-for-byte against the live response.

### 6.2 Provider streaming adapter
- `AiProviderInterface::chatStream(...)` returns `iterable<ChunkEvent>` (PHP generator).
- Default implementation falls back to one `text-delta` covering the whole response (preserves non-streaming providers like Regolo today).
- Per-provider streaming overrides land in W3.1 (only Regolo + OpenAI initially; others use the fallback).

### 6.3 Old route preserved
- `POST /conversations/{id}/messages` (synchronous) keeps working — the legacy route is exercised by PHPUnit feature tests and stays as a non-streaming fallback. New chat UI uses the streaming endpoint.

### 6.4 Tests
- New PHPUnit feature test class: `tests/Feature/Api/MessageStreamControllerTest.php`. Covers happy path (chunks emit + finish event), refusal (data-refusal events instead of text-delta), 422 on empty content, R30 cross-tenant rejection.
- Existing `MessageControllerTest` stays.

---

## 7. Test plan — la disciplina è ferrea

> Lorenzo 2026-04-30: "mi raccomando test playwright e scenari precisi
> perchè stiamo cambiando tutto il frontend con vercel".

The test suite is the only proof that a 30-component / 60-testid FE
rewrite preserved design fidelity 1:1. This section is the contract.
Codified in memory `feedback_w3_vercel_test_rigor`.

### 7.1 TestID preservation contract

- All 60+ existing testids stay on the same DOM nodes (Composer.tsx, MessageBubble.tsx, MessageThread.tsx, etc.).
- `data-role="user|assistant"` on `chat-message-{id}` from `message.role`.
- `data-state` on `chat-thread` maps SDK's `status` to the existing enum:

| SDK `status` | Our `data-state` |
|---|---|
| `submitted` (awaiting first chunk) | `loading` |
| `streaming` (chunks arriving) | `loading` |
| `ready` + `messages.length > 0` | `ready` |
| `ready` + `messages.length === 0` | `empty` |
| `error` | `error` |
| (initial, no submit yet) | `idle` |

The mapping lives in a single helper `mapStatusToDataState()` with a Vitest unit test asserting the table above.

### 7.2 Zero-edit gate on existing E2E specs

The compatibility goal is that **selectors, `getByTestId(...)`, `getByRole(...)`, ARIA expectations, `data-state` assertions, and other DOM-observable chat behaviour remain unchanged** in the four existing chat suites — `chat.spec.ts`, `chat-filters.spec.ts`, `chat-mention.spec.ts`, `chat-refusal.spec.ts`.

**Important consistency note:** today those specs stub `POST /conversations/*/messages` via `page.route()` directly inside the spec files. A naïve app switch to `POST /conversations/*/messages/stream` would break those matchers and would force per-spec edits — that is exactly the situation the zero-edit goal forbids.

The W3.2 implementation order is therefore:

1. **First** — extract the chat-network stubbing into a shared Playwright helper (e.g. `frontend/e2e/helpers/stub-chat.ts` exporting `stubChatHappyPath(page, payload)`, `stubChatRefusal(page, reason)`, etc.). The four existing spec files start delegating to this helper. This refactor itself is a small dedicated PR (or the first commit of W3.2) and the four spec files end up with TRIVIAL edits (one-line stub calls in place of the inline `page.route(...)` block).
2. **Then** — only the helper changes to point at `/conversations/*/messages/stream` and to fulfill with one AI SDK SSE event sequence (`source` + `text-delta` + `finish`). After that, the four spec files stay byte-identical AND continue to pass.

CI gate post-extraction:

```bash
# After the helper-extraction commit lands, this diff must show 0 lines for the 4 specs:
git diff --stat origin/feature/v4.0...HEAD -- frontend/e2e/chat.spec.ts frontend/e2e/chat-filters.spec.ts frontend/e2e/chat-mention.spec.ts frontend/e2e/chat-refusal.spec.ts
```

The W3.2 PR template includes both checks: (a) the helper-extraction commit is present, (b) the post-extraction diff against the 4 specs is 0 lines.

### 7.3 NEW Playwright spec files

#### `frontend/e2e/chat-stream.spec.ts` — token-level streaming UX

| # | Scenario | Asserts |
|---|---|---|
| 1 | Happy progressive render | Assistant message text grows over time — sample at t=200 ms, t=600 ms, t=2 s; text length monotonically increases; `data-state="loading"` until last chunk; `data-state="ready"` after `finish` event |
| 2 | Status transitions in order | Watch `data-state` values via `page.evaluate(...)` polling: must observe `idle → loading → ready` (no skips, no regressions) |
| 3 | Cursor / typing indicator visible during stream | `[data-testid="chat-typing-indicator"]` present while `data-state="loading"`; absent after `ready` |
| 4 | `stop()` mid-stream truncates message | After 500 ms of streaming, click `chat-composer-stop`; assert message text frozen at that point; SDK emits `finish` with `finish_reason: 'stopped'`; `data-state="ready"` |
| 5 | Network error mid-stream | `page.route(...).abort()` after first chunk; assert `data-state="error"` + `chat-thread-error` visible; previously-rendered text preserved (no rollback) |

#### `frontend/e2e/chat-stream-refusal.spec.ts` — refusal as `data-refusal` part

| # | Scenario | Asserts |
|---|---|---|
| 1 | `no_relevant_context` refusal mid-stream | Stub returns `data-refusal` events (no `text-delta`); `chat-message-{id}` has NO assistant text body; `refusal-notice` with `data-reason="no_relevant_context"` visible; grey `confidence-badge` |
| 2 | `llm_self_refusal` refusal | Same as above with `data-reason="llm_self_refusal"`; LLM-self-refusal hint visible |
| 3 | Italian-locale refusal body | BE owns localization; FE renders verbatim; assert exact Italian copy from `refusal-notice-body` |

#### `frontend/e2e/chat-stream-citations.spec.ts` — `source` parts arrive at start of stream

| # | Scenario | Asserts |
|---|---|---|
| 1 | Sources arrive before text | Stub emits `source` events first, then `text-delta`; `chat-citations` and `chat-citation-{idx}` visible BEFORE text is complete |
| 2 | Hover citation chip mid-stream | At t=500 ms (mid-stream), hover `chat-citation-0`; `chat-citations-popover` opens with title + excerpt; stream continues uninterrupted to completion |
| 3 | Citation count matches `parts.filter(type='source').length` | Stub emits 5 `source` events; assert `chat-citations` `data-count="5"` |

#### `frontend/e2e/chat-stream-filters.spec.ts` — filters round-trip during streaming

| # | Scenario | Asserts |
|---|---|---|
| 1 | Filters posted with stream request | Add source-pdf chip + folder glob; submit; intercept `POST /conversations/*/messages/stream` request body; assert `filters.source_types: ['pdf']` + `filters.folder_globs: [...]` |
| 2 | Filter chips persist during stream | While `data-state="loading"`, filter bar still shows chips; clicking `clear-all` mid-stream still works (filters clear; stream uninterrupted) |
| 3 | Filter popover open mid-stream | Click `chat-filter-bar-add` while `data-state="loading"`; popover opens normally; selecting a project doesn't stop the stream |

#### `frontend/e2e/chat-stream-mention.spec.ts` — `@mention` round-trip during streaming

| # | Scenario | Asserts |
|---|---|---|
| 1 | Mention popover during stream | Type `@policy` while previous message still streaming; mention popover renders with results; stream continues |
| 2 | Selected mention persists across stream completion | Add mention; submit; stream completes; new turn starts with `filters.doc_ids` still containing the mention |

#### `frontend/e2e/chat-stream-presets.spec.ts` — saved filter presets during streaming

| # | Scenario | Asserts |
|---|---|---|
| 1 | Load preset mid-stream | While streaming, open presets dropdown, click load on a preset; live filter state replaced; stream uninterrupted |
| 2 | Save preset from current filters | Add filters; submit (start stream); during stream, click `chat-filter-presets-save`; preset saved via `POST /api/chat-filter-presets` |

### 7.4 Visual regression — every representative state

`toHaveScreenshot({ maxDiffPixels: 0 })` (NOT ratio-based) on each:

| Spec file | State |
|---|---|
| `chat.visual.spec.ts` | empty thread (`chat-thread-empty`) |
| | composer empty / with text / with filter chips / with mention popover open / with preset menu open |
| | filter popover open on each of 7 tabs (project, tag, source, canonical, folder, date, language) |
| | thread error state |
| `chat-stream.visual.spec.ts` | mid-stream assistant (sampled at 50% completion) |
| | complete assistant with citations strip (1, 3, 5 chips) |
| | complete assistant with refusal notice (each of 3 reasons) |
| | complete assistant with confidence badge (each of 4 tiers: high / moderate / low / refused) |
| | complete assistant with thinking trace expanded |

Snapshot baselines are committed in Playwright's default per-spec `*-snapshots/` folders next to each visual spec (i.e. `frontend/e2e/chat.visual.spec.ts-snapshots/` etc.). The current `playwright.config.ts` does not customise the snapshot path template; W3.2 may set `snapshotPathTemplate` in `playwright.config.ts` to consolidate baselines under a single folder if Lorenzo prefers — that's a config choice not required for correctness. CI runs without `--update-snapshots` — pixel diff fails the build.

### 7.5 PHPUnit `MessageStreamControllerTest`

Covers:

| # | Scenario | Asserts |
|---|---|---|
| 1 | Happy path: text-delta + finish | SSE response stream parses cleanly; events in order: optional `source` events → `text-delta` events → `finish` |
| 2 | Refusal: `data-refusal` events instead of text-delta | No `text-delta` in stream; one `data-refusal` event with `reason: 'no_relevant_context'` |
| 3 | Empty content → 422 | Standard JSON 422 response (no SSE) |
| 4 | Filters round-trip | filters in body → KbSearchService called with filters → cited chunks reflect filters |
| 5 | R30 cross-tenant rejection | conversation belongs to tenant A; request from tenant B → 403 |
| 6 | R32 memory privacy | chat_log row written with correct tenant_id; no leak across tenants |
| 7 | Provider streaming fallback | provider that doesn't support streaming → SSE still emits one `text-delta` + `finish` |
| 8 | SSE protocol drift | response body matches AI SDK spec byte-for-byte (use a recorded fixture for golden comparison) |

### 7.6 Vitest unit tests

| File | Coverage |
|---|---|
| `mapStatusToDataState.test.ts` | the 6-row mapping table from §7.1 |
| `prepareSendMessagesRequest.test.ts` | filters / doc_ids passed via body parameter survive the SDK's request construction |
| `useChatAdapter.test.tsx` | optimistic add → server ack → dedupe behaviour matches the deleted `use-chat-mutation` test surface |
| `MessageBubble.parts.test.tsx` | each `parts` type renders to the correct sub-component (text → Markdown, source → CitationsPopover, data-refusal → RefusalNotice, data-confidence → ConfidenceBadge) |

### 7.7 Pre-migration baseline capture (before W3.2 starts)

Before any FE code changes in W3.2, run the FULL existing chat suite + the new stream tests on the W3.1 codebase (sync backend still active for chat*.spec.ts; SSE backend active for new chat-stream*.spec.ts) and capture:

- Playwright HTML reports → `tests/baseline/W3-pre-migration/playwright-report/`
- `toHaveScreenshot()` baselines → `tests/baseline/W3-pre-migration/screenshots/`
- Lighthouse trace for `/app/chat` → `tests/baseline/W3-pre-migration/lighthouse.json`
- Vitest snapshot files → `tests/baseline/W3-pre-migration/vitest-snapshots/`

These artefacts ARE the design-fidelity reference. The W3.2 PR acceptance gate diffs against them.

### 7.8 W3.2 PR template acceptance checklist

- [ ] `git diff --stat origin/feature/v4.0...HEAD -- frontend/e2e/chat.spec.ts frontend/e2e/chat-filters.spec.ts frontend/e2e/chat-mention.spec.ts frontend/e2e/chat-refusal.spec.ts` shows 0 lines
- [ ] All NEW chat-stream*.spec.ts files added per §7.3
- [ ] All NEW visual regression snapshots committed per §7.4
- [ ] PHPUnit `MessageStreamControllerTest` 8 scenarios green; old `MessageControllerTest` still green
- [ ] Vitest unit tests in §7.6 green
- [ ] Lighthouse INP / TTI / CLS on `/app/chat` within ±5% of W3-pre-migration baseline
- [ ] Architecture testsuite (R30/R31/R32) untouched + green
- [ ] Copilot review converged to 0 outstanding must-fix
- [ ] CI all checks COMPLETED + SUCCESS

---

## 8. Migration order

Sub-tasks within W3, each as its own PR targeting `feature/v4.0`:

| Sub-task | PR | Scope | Risk |
|---|---|---|---|
| W3.0 | this PR | Design doc + feature/v4.0-W3* branch reservation. No code. | none |
| W3.1 | (next) | Backend streaming endpoint + provider streaming adapter (Regolo + OpenAI; others fallback). PHPUnit feature tests. | medium — touches every provider in the matrix |
| W3.2 | (after) | Adopt `@ai-sdk/react` in ChatView + MessageThread + Composer. KEEP every custom feature (filters, mentions, presets, citations, wikilinks, refusal, confidence, feedback). Map all 60+ testids. New `frontend/e2e/chat-stream.spec.ts` covering streaming UX (token-by-token render). Existing chat*.spec.ts must stay green untouched. | high — design fidelity is the gate |
| W3.3 | (after) | Delete `use-chat-mutation.ts` + the legacy synchronous code paths in `chat.api.ts`. Update PHPUnit tests that referenced the synchronous `MessageController` to also cover the streaming controller. | low — pure cleanup |

Acceptance criteria for W3 done:
1. ALL existing `chat*.spec.ts` Playwright scenarios green WITHOUT modification (testid + assertion contract preserved).
2. NEW `chat-stream.spec.ts` Playwright scenario asserts token-level streaming (assistant message renders progressively, not all at once).
3. PHPUnit `MessageStreamControllerTest` green; `MessageControllerTest` still green.
4. Vitest `use-chat-mutation` tests deleted (file deleted); replacement `use-chat.test.tsx` covers SDK adapter behaviour.
5. Visual diff via Playwright `toHaveScreenshot()` snapshots (3 representative states: empty thread, mid-stream assistant, complete assistant with citations + refusal) shows zero pixel diff vs baseline (modulo cursor blink).
6. Lighthouse / web-vitals baseline (TTI, CLS, INP) on `/app/chat` is within ±5% of pre-migration baseline.

---

## 9. Risks + mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| SDK's `status` state machine differs from our `data-state` enum in a subtle way | high | E2E breaks across the suite | The mapping table in §7 is the single source of truth — encode in a `mapStatusToDataState()` helper with a test |
| Streaming endpoint latency spikes (open SSE connection cost) | medium | UX regression on slow networks | Web vitals baseline gate in §8.6 catches it; provider fallback to one-chunk ensures correctness |
| Filter / mention / preset overlays misalign with SDK's `handleSubmit` body customisation API | medium | Filters silently drop on submit | Vitest unit test on the `prepareSendMessagesRequest` adapter + Playwright `chat-filters.spec.ts` already covers the wire shape — both must pass |
| Optimistic UI flicker during the SDK's submit→streaming transition | medium | Visible "double-render" of the user's message | SDK's optimistic id is set immediately on submit; our existing `chat-message-{id}` testid uses positive ids only — verify the optimistic id path renders without test breakage |
| Wikilink overlay re-renders mid-stream as Markdown body grows | low | Hover popover flickers | Memoise `<WikilinkHover>` on `slug` so unchanged tokens don't re-mount |
| Backend streaming protocol drift across SDK versions | low | Production breaks on SDK upgrade | Pin `@ai-sdk/react` in `package.json`; add an integration test in `tests/Live/StreamProtocolTest.php` that exercises the wire format end-to-end |

---

## 10. Out of scope for W3

- Voice input (`isListening` Zustand flag) — placeholder until W7+.
- Related-graph panel (`showGraph` Zustand flag) — placeholder until W4-W7 once the graph-aware retrieval lands.
- Multi-modal attachments (file / image upload) — Vercel SDK supports them but we have no FE / BE story yet.
- Tool calls in chat (e.g. `kb:search` callable from the SDK chat) — separate ADR; defer to W7-W8.

---

## 11. Open questions for Lorenzo

1. **Streaming endpoint Option A / B / C** (§4.4) — recommend B (real streaming, +1.5 days budget). Confirm or pivot.
2. **Sub-task PR strategy** — W3.1 (BE streaming) + W3.2 (FE migration) + W3.3 (cleanup) as 3 separate PRs targeting `feature/v4.0`, or one big PR? Recommend 3 PRs for reviewability; each follows the R36 loop.
3. **Visual regression budget** — §8.5 proposes Playwright `toHaveScreenshot` snapshots for 3 representative states. Acceptable, or do you want a wider snapshot net (every spec file)?
4. **Lighthouse/INP gate** — §8.6 proposes ±5% tolerance vs pre-migration baseline. Acceptable, or stricter?
5. **W3 budget** — original W3 estimate was 1 week. Realistically with Option B + design fidelity 1:1 + visual snapshots, this is ~7-9 days of focused work. Stretch W3 to 1.5 weeks, or descope (stay synchronous, fold W3.1 streaming into W4)?

---

## 12. Once approved → execution checklist

- [ ] Create `feature/v4.0-W3.1-stream-endpoint` branch off `feature/v4.0`
- [ ] Implement streaming endpoint + provider adapter; new PHPUnit feature test class
- [ ] PR #N: R36 loop until 0 outstanding + CI green; merge
- [ ] Create `feature/v4.0-W3.2-vercel-sdk-frontend` branch off `feature/v4.0`
- [ ] Adopt `@ai-sdk/react` in ChatView/MessageThread/Composer; preserve every testid
- [ ] New `chat-stream.spec.ts` for token-level streaming UX
- [ ] PR #N+1: R36 loop; visual snapshot baseline review with Lorenzo before merge
- [ ] Create `feature/v4.0-W3.3-chat-cleanup` branch off `feature/v4.0`
- [ ] Delete `use-chat-mutation.ts` + legacy sync code paths in `chat.api.ts`; update tests
- [ ] PR #N+2: R36 loop; merge
- [ ] Write `docs/v4-platform/STATUS-{date}-week3.md` closure file in W3.3 PR

---

**Document author:** Claude Opus 4.7 (1M context) for `lorenzo.padovani@padosoft.com`.
**Review checkpoint:** Lorenzo to confirm §11 questions before W3.1 starts.
