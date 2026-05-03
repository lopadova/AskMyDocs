import type { UIMessage } from 'ai';
import type { Message as AppMessage, MessageCitation } from './chat.api';

/**
 * v4.0/W3.2 — shape adapters that let the chat renderer consume BOTH
 * the legacy `Message` shape (TanStack Query cache, persisted via the
 * synchronous `MessageController`) AND the new `UIMessage` shape
 * (delivered by `@ai-sdk/react`'s `useChat()` over W3.1's SSE
 * streaming endpoint).
 *
 * The four adapters here normalize the two shapes into a small set of
 * primitives the renderer cares about — citations, refusal reason,
 * confidence score, reasoning steps. The bigger swap commit (PLAN
 * §5.4) will let `MessageBubble` accept either shape via its props;
 * shipping the adapters first means that swap stays focused on
 * component composition without re-discovering the shape semantics.
 *
 * Discrimination rule: `'parts' in m` is the SDK shape (`UIMessage` in
 * `ai` v6 mandates a `parts: Array<UIMessagePart>` field), the legacy
 * `Message` does not have it. We deliberately do NOT key off
 * `'metadata' in m` because the SDK's `UIMessage` also has an optional
 * `metadata` slot (different semantics — generic typed metadata,
 * not the AskMyDocs `MessageMetadata` shape with `citations` /
 * `confidence` / `refusal_reason`).
 *
 * Custom data part shape: the W3.1 streaming endpoint emits
 * `data-refusal` and `data-confidence` chunks via `StreamChunk`'s flat
 * wire format `{"type":"data-refusal","reason":"…","body":"…"}`. The
 * SDK's `DataUIPart` type wraps the payload under `.data`, so the
 * normalised UI part is `{ type: 'data-refusal', data: { reason, body,
 * hint } }`. To stay resilient against either runtime shape (the
 * bigger swap commit will validate the actual round-trip), the
 * adapters read `.data.<field>` first and fall back to a flat
 * `<field>` on the part itself. Both reads pass through a
 * type-guard cast — the SDK types `.data` as `unknown`.
 */

export type RenderableMessage = AppMessage | UIMessage;

/**
 * Discriminator. The SDK's `UIMessage` mandates `parts`; the legacy
 * `Message` does not. We use this property's PRESENCE — not the
 * absence of `metadata` — because the SDK shape also has an optional
 * `metadata` field of an unrelated type.
 */
export function isUiMessage(m: RenderableMessage): m is UIMessage {
    return 'parts' in m && Array.isArray((m as UIMessage).parts);
}

const VALID_REFUSAL_REASONS = ['no_relevant_context', 'llm_self_refusal'] as const;
type KnownRefusalReason = typeof VALID_REFUSAL_REASONS[number];
// Open the union with `(string & {})` so future BE-emitted reasons
// don't silently degrade to `null` while still keeping
// IntelliSense narrowing on the known tags. Pre-T3.5 callers can
// keep treating the value as a string discriminator without code
// changes — `KbChatController` returns one of the known tags today,
// any future tag round-trips faithfully without forcing a parallel
// migration here.
type RefusalReason = KnownRefusalReason | (string & {});

function coerceRefusalReason(value: unknown): RefusalReason | null {
    if (typeof value !== 'string') {
        return null;
    }
    const normalized = value.trim();
    if (normalized.length === 0) {
        return null;
    }
    return (VALID_REFUSAL_REASONS as readonly string[]).includes(normalized)
        ? (normalized as KnownRefusalReason)
        : normalized;
}

/**
 * Read a custom data part's payload field, defending against both the
 * SDK-normalized `{ type, data: { … } }` shape AND a hypothetical flat
 * `{ type, … }` runtime shape (matches the BE's `StreamChunk` wire
 * format, which the SDK Zod normalizes when it parses each SSE chunk).
 *
 * Both shapes are read because the W3.2 swap commit may need the
 * fallback during the moment between the BE wire format being
 * normalized by the SDK Zod parser and the part being delivered to
 * the component — defensive, not load-bearing.
 */
function readDataPartField<T = unknown>(
    part: { type: string; [key: string]: unknown },
    field: string,
): T | undefined {
    const data = part.data;
    if (data && typeof data === 'object' && field in (data as Record<string, unknown>)) {
        return (data as Record<string, T>)[field];
    }
    if (field in part) {
        return part[field] as T;
    }
    return undefined;
}

/**
 * Map a BE-emitted origin tag to the legacy FE `MessageCitation.origin`
 * vocabulary (`primary` | `related` | `rejected`). The BE
 * `KbSearchService::SearchResult` uses `expanded` for graph-expanded
 * citations; `KbChatController::appendCitationsFor()` already
 * translates that to `related` on the synchronous JSON path
 * (`'expanded' => 'related'`) — see `app/Http/Controllers/Api/KbChatController.php`.
 *
 * The W3.1 streaming controller currently hard-codes `origin: 'primary'`
 * for every citation chunk (see `MessageStreamController::store()`),
 * so today this mapping only ever sees `'primary'`. The fallback
 * branches are present so a future W3.1 fix that threads the real
 * group label into the chunk doesn't require a paired FE patch —
 * the adapter accepts both legacy (`related`) and BE-internal
 * (`expanded`) spellings.
 *
 * Unknown values default to `'primary'` so the existing
 * `CitationsPopover` always has a valid bucket to render into.
 */
type CitationOrigin = NonNullable<MessageCitation['origin']>;

function coerceCitationOrigin(value: unknown): CitationOrigin {
    if (typeof value !== 'string') {
        return 'primary';
    }
    if (value === 'primary' || value === 'related' || value === 'rejected') {
        return value;
    }
    if (value === 'expanded') {
        // BE-internal alias — translated to the FE legacy name so
        // the existing CitationsPopover bucket continues to render.
        return 'related';
    }
    return 'primary';
}

/**
 * Convert a SDK `source-url` part (the SDK v6 canonical citation
 * shape, emitted by `MessageStreamController::store()` post-PR #90)
 * to the AskMyDocs `MessageCitation` shape.
 *
 * **`sourceId` shape**: the streaming controller emits
 * `'doc-' . $document->id` (see `MessageStreamController::store()`).
 * The adapter strips the `doc-` prefix before parsing the numeric
 * tail so the `WikilinkHover` numeric-id resolution path fires for
 * the happy path. Non-prefixed payloads (canonical slug-only
 * citations) pass through verbatim, and any sourceId whose tail
 * isn't a positive integer yields `document_id: null` —
 * `WikilinkHover` then falls back to title-based resolution.
 *
 * **`origin` mapping**: `coerceCitationOrigin()` maps `expanded` →
 * `related` for FE legacy compatibility, leaves `primary` / `related`
 * / `rejected` unchanged, and defaults unknown values to `primary` so
 * the existing `CitationsPopover` always has a valid bucket.
 *
 * **`url`** is typed as nullable to keep this adapter resilient
 * against future BE shapes; the production BE
 * (`MessageStreamController::store()`) always synthesises a non-null
 * fallback URL before calling `StreamChunk::sourceUrl()` (canonical
 * slug-anchored when the doc has no public URL), so today every
 * SDK chunk arrives with a string `url`. If `url` ever does arrive
 * `null` AND `title` is missing, the chip falls back to the source
 * id (with `doc-` prefix retained as a last-resort label) so the
 * UI never renders an empty cell.
 *
 * Other citation fields (source_path, source_type, headings,
 * chunks_used) live in the AskMyDocs domain and don't have a place
 * in the SDK's source part — they land as `null` / sensible defaults
 * so the existing `CitationsPopover` still renders.
 */
function sourcePartToCitation(part: {
    sourceId: string;
    title?: string;
    url?: string | null;
    origin?: string;
}): MessageCitation {
    // Strip the BE `doc-` prefix (see MessageStreamController::store()
    // line 369 — `'doc-' . ($citation['document_id'] ?? 'unknown')`).
    // Without this, the cycle-5 strict-integer check `Number('doc-42')`
    // returns NaN, so every citation would land with document_id:null
    // and the wikilink numeric-id path would never fire.
    const idTail = part.sourceId.startsWith('doc-')
        ? part.sourceId.slice('doc-'.length)
        : part.sourceId;
    // Strict integer parse — `Number.parseInt('42abc', 10)` returns 42
    // (parses leading digits then stops), silently misclassifying
    // partially-numeric tails (`'doc-42abc'` post-strip) as document_id
    // 42. `Number()` rejects strictly: NaN for any non-numeric input.
    // `Number.isInteger()` additionally rejects floats and Infinity.
    const numeric = Number(idTail);
    const documentId = Number.isInteger(numeric) && numeric > 0 ? numeric : null;
    const label = part.title ?? part.url ?? part.sourceId;
    return {
        document_id: documentId,
        title: label,
        source_path: null,
        source_type: null,
        headings: [],
        chunks_used: 1,
        origin: coerceCitationOrigin(part.origin),
    };
}

export function getCitations(m: RenderableMessage): MessageCitation[] {
    if (!isUiMessage(m)) {
        return m.metadata?.citations ?? [];
    }
    const citations: MessageCitation[] = [];
    for (const part of m.parts) {
        if (part.type !== 'source-url') {
            continue;
        }
        citations.push(sourcePartToCitation(part as unknown as {
            sourceId: string;
            title?: string;
            url?: string | null;
            origin?: string;
        }));
    }
    return citations;
}

export function getRefusalReason(m: RenderableMessage): RefusalReason | null {
    if (!isUiMessage(m)) {
        // T3.5 — top-level mirrors metadata; prefer top-level so a
        // BE that has migrated to top-level-only stays consistent.
        const top = coerceRefusalReason(m.refusal_reason);
        if (top !== null) {
            return top;
        }
        return coerceRefusalReason(m.metadata?.refusal_reason);
    }
    for (const part of m.parts) {
        if (part.type !== 'data-refusal') {
            continue;
        }
        // Cast through unknown — the SDK types data parts via a
        // generic `DATA_TYPES` map that we deliberately don't
        // populate (the bigger swap commit will), so at this layer
        // the field reads are unknown.
        const reason = readDataPartField<unknown>(
            part as unknown as { type: string; [key: string]: unknown },
            'reason',
        );
        return coerceRefusalReason(reason);
    }
    return null;
}

export function getConfidence(m: RenderableMessage): number | null {
    if (!isUiMessage(m)) {
        // T3.5 — top-level wins; metadata is the legacy fallback for
        // pre-T3.5 rows that haven't been re-fetched yet.
        if (typeof m.confidence === 'number') {
            return m.confidence;
        }
        if (m.confidence === null) {
            // Explicit null at the top level beats a stale metadata
            // value (refusal payloads put `0` here intentionally).
            return null;
        }
        const meta = m.metadata?.confidence;
        return typeof meta === 'number' ? meta : null;
    }
    for (const part of m.parts) {
        if (part.type !== 'data-confidence') {
            continue;
        }
        const value = readDataPartField<unknown>(
            part as unknown as { type: string; [key: string]: unknown },
            'confidence',
        );
        if (typeof value === 'number') {
            return value;
        }
        return null;
    }
    return null;
}

export function getReasoningSteps(m: RenderableMessage): string[] | undefined {
    if (!isUiMessage(m)) {
        const steps = m.metadata?.reasoning_steps;
        if (!Array.isArray(steps) || !steps.every((s) => typeof s === 'string')) {
            return undefined;
        }
        return steps;
    }
    const steps: string[] = [];
    for (const part of m.parts) {
        if (part.type !== 'reasoning') {
            continue;
        }
        // ReasoningUIPart per `ai` v6: `{ type: 'reasoning', text: string, … }`.
        const text = (part as { text?: unknown }).text;
        if (typeof text === 'string') {
            steps.push(text);
        }
    }
    return steps.length === 0 ? undefined : steps;
}

/**
 * Extract the refusal body string from a refusal message. For
 * AppMessage (legacy synchronous flow) the BE persists the localized
 * refusal string as the message's top-level `content` field. For
 * UIMessage (SDK streaming flow) the BE emits `data-refusal` with
 * the body field. The adapter reads via `readDataPartField`, which
 * checks the SDK-normalized shape (`part.data.body`) first and
 * falls back to a flat shape (`part.body`) for resilience against
 * the BE wire format that may not yet wrap the payload under `data`.
 * The BE DOES NOT emit a `text-delta` chunk on the refusal path
 * (see `StreamChunk::dataRefusal` — W3.1 design choice that keeps
 * refusal payloads cleanly separable from grounded answers). Without
 * this dedicated helper,
 * `getTextContent(uiRefusal)` returns "" because there are no
 * text parts to join, and `RefusalNotice` renders an empty body.
 *
 * Returns `null` when no refusal payload is present so the caller
 * (MessageBubble) can guard cleanly. The `null` branch only fires
 * when `getRefusalReason` would also return `null`, i.e. the
 * message isn't a refusal — callers should compute both together.
 */
export function getRefusalBody(m: RenderableMessage): string | null {
    // Defer to `getRefusalReason` for the "is this a refusal?"
    // decision so the two helpers stay consistent: empty/whitespace
    // refusal_reason strings coerce to null in both. Without this
    // shared decision, an AppMessage with `refusal_reason: ''` (or
    // whitespace) would have `getRefusalReason() === null` (via
    // `coerceRefusalReason`'s trim+length check) but
    // `getRefusalBody()` would return `m.content` because the
    // earlier `!= null` check accepts empty strings.
    if (getRefusalReason(m) === null) {
        return null;
    }
    if (!isUiMessage(m)) {
        return m.content ?? null;
    }
    for (const part of m.parts) {
        if (part.type !== 'data-refusal') {
            continue;
        }
        const body = readDataPartField<unknown>(
            part as unknown as { type: string; [key: string]: unknown },
            'body',
        );
        return typeof body === 'string' ? body : null;
    }
    return null;
}

/**
 * Extract the user-visible text content of a message. For `AppMessage`
 * (legacy synchronous flow) this is the top-level `content` field.
 * For `UIMessage` (SDK streaming flow) the SDK splits the body into
 * `parts: UIMessagePart[]`; we join all `text` parts in order so the
 * renderer sees a coherent string body.
 *
 * Used by `MessageBubble` after the swap commit lands the
 * `useChatStream()` integration — the bubble must render the same
 * string regardless of which shape lands in props.
 *
 * For refusal payloads the body lives in `data-refusal.body` not in
 * text-delta chunks; callers that may render either should also
 * call `getRefusalBody` and prefer its result when present.
 */
export function getTextContent(m: RenderableMessage): string {
    if (!isUiMessage(m)) {
        return m.content ?? '';
    }
    return m.parts
        .filter((p): p is { type: 'text'; text: string } => p.type === 'text')
        .map((p) => p.text)
        .join('');
}

/**
 * Return the message id verbatim. `AppMessage.id` is a number (BE
 * persisted ids; optimistic placeholders use negative numbers), while
 * `UIMessage.id` is a string (SDK convention). The renderer uses the
 * id only for keys + the `chat-message-{id}` testid template — both
 * uses tolerate either form because:
 *   1. React's `key={id}` accepts string or number.
 *   2. The testid template stringifies via template literals.
 *
 * Returning `string | number` keeps the typed surface honest; callers
 * that need a stable string (e.g. the testid attribute) just use it
 * inside a template literal.
 */
export function getMessageId(m: RenderableMessage): string | number {
    return m.id;
}
