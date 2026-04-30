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
type RefusalReason = typeof VALID_REFUSAL_REASONS[number];

function coerceRefusalReason(value: unknown): RefusalReason | null {
    if (typeof value !== 'string') {
        return null;
    }
    return (VALID_REFUSAL_REASONS as readonly string[]).includes(value)
        ? (value as RefusalReason)
        : null;
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
 * Convert a SDK `source-url` part to the AskMyDocs `MessageCitation`
 * shape. Only `title` round-trips faithfully; the other citation
 * fields (document_id, source_path, source_type, headings,
 * chunks_used) live in the AskMyDocs domain and don't have a place in
 * the SDK's source part. They land as `null` / sensible defaults so
 * the existing `CitationsPopover` still renders without changes — the
 * popover already tolerates absent fields.
 *
 * `document_id` we attempt to recover from `sourceId` only when it
 * looks like a positive integer (the W3.1 `StreamChunk::source()`
 * factory passes `(string) $document->id`). When the BE later
 * switches to non-numeric source ids (e.g. UUIDs), this conversion
 * yields `null` and `WikilinkHover` falls back to title-based
 * resolution — same path the legacy `Message.metadata` flow uses for
 * imported citations that lacked a numeric id.
 */
function sourceUrlPartToCitation(part: {
    sourceId: string;
    title?: string;
    url: string;
}): MessageCitation {
    const numeric = Number.parseInt(part.sourceId, 10);
    const documentId = Number.isFinite(numeric) && numeric > 0 ? numeric : null;
    return {
        document_id: documentId,
        title: part.title ?? part.url,
        source_path: null,
        source_type: null,
        headings: [],
        chunks_used: 1,
        origin: 'primary',
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
        // Narrow to the SourceUrlUIPart shape for the conversion.
        citations.push(sourceUrlPartToCitation(part));
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
