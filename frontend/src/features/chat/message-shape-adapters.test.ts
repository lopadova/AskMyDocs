import { describe, it, expect } from 'vitest';
import type { UIMessage } from 'ai';
import {
    getCitations,
    getConfidence,
    getMessageId,
    getReasoningSteps,
    getRefusalBody,
    getRefusalReason,
    getTextContent,
    isUiMessage,
} from './message-shape-adapters';
import type { Message as AppMessage, MessageCitation } from './chat.api';

/**
 * v4.0/W3.2 — unit coverage for the four shape adapters that let
 * `MessageBubble` consume BOTH the legacy `Message` shape (TanStack
 * cache + persisted DB row) and the SDK `UIMessage` shape (delivered
 * by `useChat()` over the W3.1 SSE endpoint). Each adapter is
 * exercised against (1) AppMessage happy path, (2) UIMessage happy
 * path, (3) null / missing / empty input, and (4) at least one edge
 * case that drives the precedence rule (top-level vs metadata for
 * AppMessage; mixed-parts ordering for UIMessage).
 */

function appMsg(overrides: Partial<AppMessage> = {}): AppMessage {
    return {
        id: 1,
        role: 'assistant',
        content: 'Hello',
        metadata: null,
        rating: null,
        created_at: '2026-04-30T20:00:00Z',
        ...overrides,
    };
}

function uiMsg(overrides: Partial<UIMessage> & { parts: UIMessage['parts'] }): UIMessage {
    return {
        id: '1',
        role: 'assistant',
        ...overrides,
    } as UIMessage;
}

describe('isUiMessage', () => {
    it('returns true for a SDK UIMessage with parts array', () => {
        expect(isUiMessage(uiMsg({ parts: [{ type: 'text', text: 'hi' }] }))).toBe(true);
    });

    it('returns false for a legacy AppMessage', () => {
        expect(isUiMessage(appMsg())).toBe(false);
    });
});

describe('getCitations', () => {
    it('reads citations from AppMessage.metadata.citations', () => {
        const citations: MessageCitation[] = [
            {
                document_id: 42,
                title: 'Vacation Policy',
                source_path: 'hr/vacation.md',
                source_type: 'markdown',
                headings: ['Policy', 'Eligibility'],
                chunks_used: 3,
                origin: 'primary',
            },
        ];
        const m = appMsg({
            metadata: {
                citations,
                provider: 'openai',
                model: 'gpt-4',
            },
        });

        expect(getCitations(m)).toEqual(citations);
    });

    it('returns [] for AppMessage with metadata=null (legacy row)', () => {
        expect(getCitations(appMsg({ metadata: null }))).toEqual([]);
    });

    it('returns [] for AppMessage whose metadata has no citations key', () => {
        expect(getCitations(appMsg({ metadata: { provider: 'openai' } }))).toEqual([]);
    });

    it('converts SDK source parts (BE wire-format type=source, sourceId=doc-N) to MessageCitation shape', () => {
        // PLAN-W3 §5.5 mandates the discriminator `p.type === 'source'`
        // — a CUSTOM extension to the SDK's UIPart type union which
        // only knows `source-url` / `source-document` natively. The
        // SDK passes unknown chunk types through verbatim, so the
        // adapter sees `type: 'source'` and the `as never` cast is
        // necessary to satisfy TypeScript's structural check on
        // UIPart.type.
        //
        // The W3.1 streaming controller emits sourceId as
        // `'doc-' . $document->id` (see MessageStreamController::store()
        // line 369). The adapter strips the prefix before parsing the
        // numeric tail. Fixtures here mirror the real BE wire shape.
        const m = uiMsg({
            parts: [
                { type: 'text', text: 'According to the docs…' },
                { type: 'source', sourceId: 'doc-42', title: 'Vacation Policy', url: '/kb/42', origin: 'primary' } as never,
                { type: 'source', sourceId: 'doc-7', title: 'Onboarding', url: '/kb/7', origin: 'primary' } as never,
            ],
        });

        const citations = getCitations(m);
        expect(citations).toHaveLength(2);
        expect(citations[0]).toEqual({
            document_id: 42,
            title: 'Vacation Policy',
            source_path: null,
            source_type: null,
            headings: [],
            chunks_used: 1,
            origin: 'primary',
        });
        expect(citations[1].document_id).toBe(7);
        expect(citations[1].title).toBe('Onboarding');
    });

    it('strips BE doc- prefix from sourceId before parsing document_id', () => {
        // Pin the BE wire shape: MessageStreamController::store() emits
        // `'doc-' . $document->id`. The adapter MUST strip this before
        // parsing — without the strip, every citation would land with
        // document_id:null because Number('doc-42') is NaN.
        const cases = [
            { sourceId: 'doc-42', expectDocId: 42 },
            { sourceId: 'doc-1', expectDocId: 1 },
            { sourceId: 'doc-9999', expectDocId: 9999 },
            // Unprefixed numeric still works (forward-compat with
            // a future BE patch that drops the prefix).
            { sourceId: '42', expectDocId: 42 },
            // doc-unknown — BE fallback when document_id is null
            { sourceId: 'doc-unknown', expectDocId: null },
            // Malformed prefix tail still rejects
            { sourceId: 'doc-42abc', expectDocId: null },
        ];
        for (const c of cases) {
            const m = uiMsg({
                parts: [{ type: 'source', sourceId: c.sourceId, title: 'X', url: '/x', origin: 'primary' } as never],
            });
            expect(getCitations(m)[0].document_id).toBe(c.expectDocId);
        }
    });

    it('returns [] for SDK UIMessage with empty parts array', () => {
        expect(getCitations(uiMsg({ parts: [] }))).toEqual([]);
    });

    it("ALSO matches SDK-native 'source-url' variant — handles both discriminators during BE migration", () => {
        // PR #88's design pinned the BE's custom `'source'`
        // discriminator (PLAN-W3 §5.5). PR #89 discovered the SDK's
        // stream parser actually emits `'source-url'` per the v6
        // UIMessageChunk union. Until the W3.1 BE catches up, the
        // adapter accepts BOTH so production traffic and SDK-native
        // stubs both render citations correctly.
        const m = uiMsg({
            parts: [
                { type: 'source-url', sourceId: 'doc-99', title: 'SDK-native shape', url: '/kb/99' },
            ],
        });
        const citations = getCitations(m);
        expect(citations).toHaveLength(1);
        expect(citations[0].document_id).toBe(99);
        expect(citations[0].title).toBe('SDK-native shape');
    });

    it('falls back to title=url when source part has no title', () => {
        const m = uiMsg({
            parts: [{ type: 'source', sourceId: 'abc', url: 'https://example.com/foo', origin: 'primary' } as never],
        });

        expect(getCitations(m)[0].title).toBe('https://example.com/foo');
        // Non-numeric sourceId leaves document_id null so the FE
        // falls back to title-based resolution.
        expect(getCitations(m)[0].document_id).toBeNull();
    });

    it('rejects malformed integer sourceIds (strict parse, post-strip)', () => {
        // Number.parseInt('42abc', 10) returns 42 (parses leading
        // digits), which would silently misclassify slug-like ids
        // as numeric document_ids. The adapter uses Number() +
        // Number.isInteger() so only fully-numeric strings round-
        // trip; everything else yields document_id=null and
        // WikilinkHover falls back to title-based resolution.
        // Cases below cover BOTH unprefixed AND BE-prefixed shapes.
        const cases = [
            { sourceId: '42abc', expectDocId: null },             // suffix garbage
            { sourceId: 'doc-42abc', expectDocId: null },         // post-strip suffix garbage
            { sourceId: 'dec-cache-v2', expectDocId: null },      // canonical slug
            { sourceId: 'doc-cache-v2', expectDocId: null },      // looks like prefix but tail isn't numeric
            { sourceId: '42.5', expectDocId: null },              // float rejected
            { sourceId: 'doc-42.5', expectDocId: null },          // float post-strip
            { sourceId: '0', expectDocId: null },                 // non-positive rejected
            { sourceId: 'doc-0', expectDocId: null },             // non-positive post-strip
            { sourceId: '-1', expectDocId: null },                // negative rejected
            { sourceId: 'doc--1', expectDocId: null },            // negative post-strip (double-hyphen)
        ];
        for (const c of cases) {
            const m = uiMsg({
                parts: [{ type: 'source', sourceId: c.sourceId, title: 'X', url: '/x', origin: 'primary' } as never],
            });
            expect(getCitations(m)[0].document_id).toBe(c.expectDocId);
        }
    });

    it('falls back to title=sourceId when source part has neither title nor url (canonical citation)', () => {
        // Canonical citations may emit a `source` chunk with no
        // public URL — `StreamChunk::source(?string $url)` allows
        // null. The chip must still render a non-empty label so
        // the user sees the citation; we fall back to sourceId.
        const m = uiMsg({
            parts: [{ type: 'source', sourceId: 'dec-cache-v2', url: null, origin: 'primary' } as never],
        });
        expect(getCitations(m)[0].title).toBe('dec-cache-v2');
    });

    it('round-trips BE-provided origin tags onto FE legacy vocabulary', () => {
        // BE `KbSearchService::SearchResult` groupings: primary |
        // expanded | rejected. FE `MessageCitation.origin` legacy
        // vocabulary: primary | related | rejected. Adapter maps
        // expanded → related to keep the existing CitationsPopover
        // bucket logic unchanged. (W3.1 BE today hard-codes 'primary'
        // for every source chunk; this mapping is forward-compatible
        // with a future BE patch that threads the real group label.)
        const m = uiMsg({
            parts: [
                { type: 'source', sourceId: '1', title: 'Primary doc', url: '/kb/1', origin: 'primary' } as never,
                { type: 'source', sourceId: '2', title: 'Expanded via graph', url: '/kb/2', origin: 'expanded' } as never,
                { type: 'source', sourceId: '3', title: 'Rejected approach', url: '/kb/3', origin: 'rejected' } as never,
                { type: 'source', sourceId: '4', title: 'Already-translated alias', url: '/kb/4', origin: 'related' } as never,
            ],
        });
        const citations = getCitations(m);
        expect(citations[0].origin).toBe('primary');
        expect(citations[1].origin).toBe('related'); // expanded → related
        expect(citations[2].origin).toBe('rejected');
        expect(citations[3].origin).toBe('related'); // pass-through
    });

    it('coerces unknown / missing origin to primary (defensive)', () => {
        const m = uiMsg({
            parts: [
                { type: 'source', sourceId: '1', title: 'A', url: '/a' /* origin missing */ } as never,
                { type: 'source', sourceId: '2', title: 'B', url: '/b', origin: 'totally-made-up' } as never,
            ],
        });
        const citations = getCitations(m);
        expect(citations[0].origin).toBe('primary');
        expect(citations[1].origin).toBe('primary');
    });
});

describe('getRefusalReason', () => {
    it('reads top-level refusal_reason on AppMessage (T3.5)', () => {
        const m = appMsg({ refusal_reason: 'no_relevant_context' });
        expect(getRefusalReason(m)).toBe('no_relevant_context');
    });

    it('falls back to metadata.refusal_reason when top-level is missing', () => {
        const m = appMsg({
            metadata: {
                refusal_reason: 'llm_self_refusal',
            },
        });
        expect(getRefusalReason(m)).toBe('llm_self_refusal');
    });

    it('top-level wins over metadata when both are populated (T3.5 precedence)', () => {
        const m = appMsg({
            refusal_reason: 'no_relevant_context',
            metadata: { refusal_reason: 'llm_self_refusal' },
        });
        expect(getRefusalReason(m)).toBe('no_relevant_context');
    });

    it('returns null when neither top-level nor metadata holds a refusal reason', () => {
        expect(getRefusalReason(appMsg())).toBeNull();
        expect(getRefusalReason(appMsg({ metadata: { provider: 'openai' } }))).toBeNull();
    });

    it('passes unknown refusal_reason values through as-is (open string union)', () => {
        // The type now uses `KnownRefusalReason | (string & {})` so a
        // future BE-emitted reason ('rate_limited', 'safety_filter',
        // ...) round-trips faithfully without forcing a parallel FE
        // migration. Empty / non-string values still coerce to null.
        const m = appMsg({ refusal_reason: 'totally-made-up' as unknown as string });
        expect(getRefusalReason(m)).toBe('totally-made-up');
    });

    it('returns null for empty / whitespace-only refusal_reason strings', () => {
        const empty = appMsg({ refusal_reason: '' as unknown as string });
        const whitespace = appMsg({ refusal_reason: '   ' as unknown as string });
        expect(getRefusalReason(empty)).toBeNull();
        expect(getRefusalReason(whitespace)).toBeNull();
    });

    it('reads SDK data-refusal part (.data.reason — normalized SDK shape)', () => {
        const m = uiMsg({
            parts: [
                { type: 'text', text: 'Here is what I found' },
                {
                    type: 'data-refusal',
                    data: { reason: 'no_relevant_context', body: 'No context.' },
                } as never,
            ],
        });
        expect(getRefusalReason(m)).toBe('no_relevant_context');
    });

    it('reads SDK data-refusal part flat-shape fallback (resilience)', () => {
        // The W3.1 BE wire format is flat; the SDK Zod normalizes it,
        // but the adapter falls back to flat-read so a parse mismatch
        // doesn't blank the refusal notice silently.
        const m = uiMsg({
            parts: [{ type: 'data-refusal', reason: 'llm_self_refusal' } as never],
        });
        expect(getRefusalReason(m)).toBe('llm_self_refusal');
    });

    it('returns null for SDK UIMessage with no data-refusal part', () => {
        const m = uiMsg({
            parts: [
                { type: 'text', text: 'A clean answer' },
                { type: 'source', sourceId: '1', url: '/kb/1', title: 'Doc', origin: 'primary' } as never,
            ],
        });
        expect(getRefusalReason(m)).toBeNull();
    });
});

describe('getConfidence', () => {
    it('reads top-level confidence on AppMessage', () => {
        expect(getConfidence(appMsg({ confidence: 87 }))).toBe(87);
    });

    it('preserves confidence=0 (refusal payloads put 0 here intentionally)', () => {
        // Critical: the original code used `?? `, which keeps `0`.
        // The adapter must do the same — a refusal turn carries
        // `confidence=0` AND a non-null `refusal_reason`; ConfidenceBadge
        // uses both signals. Coercing `0` to null would change the
        // refusal-tier render path.
        expect(getConfidence(appMsg({ confidence: 0, refusal_reason: 'no_relevant_context' }))).toBe(0);
    });

    it('falls back to metadata.confidence when top-level is undefined', () => {
        const m = appMsg({ metadata: { confidence: 73 } });
        expect(getConfidence(m)).toBe(73);
    });

    it('returns null when both top-level and metadata are absent', () => {
        expect(getConfidence(appMsg())).toBeNull();
        expect(getConfidence(appMsg({ metadata: { provider: 'openai' } }))).toBeNull();
    });

    it('reads SDK data-confidence part (.data.confidence — normalized SDK shape)', () => {
        const m = uiMsg({
            parts: [
                { type: 'text', text: 'Here we go' },
                { type: 'data-confidence', data: { confidence: 82, tier: 'high' } } as never,
            ],
        });
        expect(getConfidence(m)).toBe(82);
    });

    it('returns null for SDK refusal-tier confidence (confidence=null payload)', () => {
        const m = uiMsg({
            parts: [{ type: 'data-confidence', data: { confidence: null, tier: 'refused' } } as never],
        });
        expect(getConfidence(m)).toBeNull();
    });

    it('returns null for SDK UIMessage with no data-confidence part', () => {
        const m = uiMsg({
            parts: [{ type: 'text', text: 'plain answer' }],
        });
        expect(getConfidence(m)).toBeNull();
    });
});

describe('getReasoningSteps', () => {
    it('reads metadata.reasoning_steps from AppMessage when present', () => {
        const steps = ['Identify intent', 'Search KB', 'Synthesize answer'];
        const m = appMsg({ metadata: { reasoning_steps: steps } });
        expect(getReasoningSteps(m)).toEqual(steps);
    });

    it('returns undefined for AppMessage with metadata=null', () => {
        // Matches the existing component's "skip ThinkingTrace when
        // absent" semantics — the renderer checks `thinking && <…>`
        // so only the undefined value short-circuits.
        expect(getReasoningSteps(appMsg({ metadata: null }))).toBeUndefined();
    });

    it('returns undefined when metadata.reasoning_steps is missing', () => {
        expect(getReasoningSteps(appMsg({ metadata: { provider: 'openai' } }))).toBeUndefined();
    });

    it('returns undefined when metadata.reasoning_steps contains non-strings (defensive)', () => {
        const m = appMsg({
            metadata: { reasoning_steps: ['ok', 42 as unknown as string] },
        });
        expect(getReasoningSteps(m)).toBeUndefined();
    });

    it('reads reasoning parts from a SDK UIMessage', () => {
        const m = uiMsg({
            parts: [
                { type: 'reasoning', text: 'Let me think about this…' },
                { type: 'text', text: 'Here is my answer' },
                { type: 'reasoning', text: 'Actually, also checking edge case' },
            ],
        });
        expect(getReasoningSteps(m)).toEqual([
            'Let me think about this…',
            'Actually, also checking edge case',
        ]);
    });

    it('returns undefined when SDK UIMessage has no reasoning parts (matches AppMessage semantics)', () => {
        const m = uiMsg({
            parts: [{ type: 'text', text: 'plain answer' }],
        });
        expect(getReasoningSteps(m)).toBeUndefined();
    });

    it('returns undefined for SDK UIMessage with empty parts array', () => {
        expect(getReasoningSteps(uiMsg({ parts: [] }))).toBeUndefined();
    });
});

describe('mixed-shape integration', () => {
    it('SDK UIMessage with text + source + reasoning + data parts interleaved drives every adapter consistently', () => {
        const m = uiMsg({
            parts: [
                { type: 'reasoning', text: 'Let me check the docs first' },
                { type: 'text', text: 'According to the policy…' },
                { type: 'source', sourceId: '99', title: 'Policy', url: '/kb/99', origin: 'primary' } as never,
                { type: 'data-confidence', data: { confidence: 64, tier: 'moderate' } } as never,
                // No data-refusal part — this is a normal answer turn.
            ],
        });

        expect(isUiMessage(m)).toBe(true);
        expect(getReasoningSteps(m)).toEqual(['Let me check the docs first']);
        expect(getCitations(m)).toHaveLength(1);
        expect(getCitations(m)[0].document_id).toBe(99);
        expect(getConfidence(m)).toBe(64);
        expect(getRefusalReason(m)).toBeNull();
    });
});

describe('getTextContent', () => {
    it('returns top-level content for AppMessage', () => {
        expect(getTextContent({
            id: 1,
            role: 'user',
            content: 'How does PTO work?',
            metadata: null,
            rating: null,
            created_at: '2026-04-30T20:00:00Z',
        } satisfies AppMessage)).toBe('How does PTO work?');
    });

    it('returns empty string when AppMessage has no content', () => {
        expect(getTextContent({
            id: 1,
            role: 'assistant',
            content: '',
            metadata: null,
            rating: null,
            created_at: '2026-04-30T20:00:00Z',
        } satisfies AppMessage)).toBe('');
    });

    it('joins all text parts in order for UIMessage', () => {
        const m: UIMessage = {
            id: '7',
            role: 'assistant',
            parts: [
                { type: 'text', text: 'Hello, ' },
                { type: 'text', text: 'world.' },
            ],
        };
        expect(getTextContent(m)).toBe('Hello, world.');
    });

    it('skips non-text parts (source, reasoning, data) when joining', () => {
        const m: UIMessage = {
            id: '7',
            role: 'assistant',
            parts: [
                { type: 'reasoning', text: 'Thinking…' } as never,
                { type: 'text', text: 'According to the docs, ' },
                { type: 'source', sourceId: 'doc-42', title: 'Policy', url: '/kb/42', origin: 'primary' } as never,
                { type: 'text', text: 'PTO accrues monthly.' },
            ],
        };
        expect(getTextContent(m)).toBe('According to the docs, PTO accrues monthly.');
    });

    it('returns empty string for UIMessage with no text parts', () => {
        const m: UIMessage = {
            id: '7',
            role: 'assistant',
            parts: [
                { type: 'reasoning', text: 'Hmm' } as never,
            ],
        };
        expect(getTextContent(m)).toBe('');
    });
});

describe('getMessageId', () => {
    it('returns numeric id verbatim for AppMessage', () => {
        expect(getMessageId({
            id: 42,
            role: 'user',
            content: 'X',
            metadata: null,
            rating: null,
            created_at: '2026-04-30T20:00:00Z',
        } satisfies AppMessage)).toBe(42);
    });

    it('returns string id verbatim for UIMessage', () => {
        const m: UIMessage = {
            id: 'msg-abc-123',
            role: 'assistant',
            parts: [{ type: 'text', text: 'X' }],
        };
        expect(getMessageId(m)).toBe('msg-abc-123');
    });

    it('returns negative numeric id for optimistic AppMessage placeholders', () => {
        // Optimistic placeholders in the legacy mutation flow used
        // negative ids; we keep them so the test contract holds.
        // (After the swap, the SDK manages the optimistic placeholder
        // directly and AppMessage with negative ids no longer flows
        // through MessageBubble — but the adapter must not silently
        // mangle them either.)
        expect(getMessageId({
            id: -123,
            role: 'user',
            content: 'X',
            metadata: null,
            rating: null,
            created_at: '2026-04-30T20:00:00Z',
        } satisfies AppMessage)).toBe(-123);
    });
});

describe('getRefusalBody', () => {
    it('returns content for AppMessage with top-level refusal_reason', () => {
        const m = appMsg({
            role: 'assistant',
            content: 'No documents in the knowledge base match this question.',
            refusal_reason: 'no_relevant_context',
        });
        expect(getRefusalBody(m)).toBe('No documents in the knowledge base match this question.');
    });

    it('returns content for AppMessage with refusal_reason inside metadata', () => {
        const m = appMsg({
            role: 'assistant',
            content: 'AI cannot answer based on docs.',
            metadata: {
                provider: 'openai',
                refusal_reason: 'llm_self_refusal',
            },
        });
        expect(getRefusalBody(m)).toBe('AI cannot answer based on docs.');
    });

    it('returns null for AppMessage that is NOT a refusal (no refusal_reason)', () => {
        const m = appMsg({
            role: 'assistant',
            content: 'Grounded answer body',
            refusal_reason: null,
            metadata: { provider: 'openai' },
        });
        expect(getRefusalBody(m)).toBeNull();
    });

    it('reads body from SDK data-refusal part (.data.body — normalized SDK shape)', () => {
        const m: UIMessage = {
            id: '7',
            role: 'assistant',
            parts: [
                {
                    type: 'data-refusal',
                    data: {
                        reason: 'no_relevant_context',
                        body: 'No documents match.',
                    },
                } as never,
            ],
        };
        expect(getRefusalBody(m)).toBe('No documents match.');
    });

    it('reads body from data-refusal flat-shape fallback', () => {
        // BE wire format may surface body at the part's top level
        // before the SDK normalizes; the helper handles both. The
        // part needs a `reason` field too — the helper now defers
        // the "is this a refusal?" decision to `getRefusalReason`,
        // which requires a non-empty reason.
        const m: UIMessage = {
            id: '7',
            role: 'assistant',
            parts: [{ type: 'data-refusal', reason: 'no_relevant_context', body: 'Flat-shape body' } as never],
        };
        expect(getRefusalBody(m)).toBe('Flat-shape body');
    });

    it('returns null for UIMessage with no data-refusal part', () => {
        const m: UIMessage = {
            id: '7',
            role: 'assistant',
            parts: [{ type: 'text', text: 'A grounded answer' }],
        };
        expect(getRefusalBody(m)).toBeNull();
    });

    it('returns null for empty/whitespace refusal_reason — consistent with getRefusalReason', () => {
        // The two helpers share the same "is this a refusal?"
        // decision. Empty/whitespace refusal_reason coerces to null
        // in getRefusalReason; getRefusalBody must agree so a stale
        // row with refusal_reason='' doesn't render content as if
        // it were a refusal body.
        const empty = appMsg({
            role: 'assistant',
            content: 'Should not render as refusal',
            refusal_reason: '' as unknown as string,
        });
        const whitespace = appMsg({
            role: 'assistant',
            content: 'Should not render as refusal',
            refusal_reason: '   ' as unknown as string,
        });
        expect(getRefusalBody(empty)).toBeNull();
        expect(getRefusalBody(whitespace)).toBeNull();
        // Sanity-check the contract pairing: getRefusalReason agrees.
        expect(getRefusalReason(empty)).toBeNull();
        expect(getRefusalReason(whitespace)).toBeNull();
    });
});
