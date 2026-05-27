import { describe, it, expect, vi } from 'vitest';
import { readUIMessageStream, uiMessageChunkSchema, type UIMessageChunk } from 'ai';
import { safeValidateTypes } from '@ai-sdk/provider-utils';
import { getCitations, getTextContent } from './message-shape-adapters';

// The SDK ships `uiMessageChunkSchema` as a LazySchema (Standard Schema), so we
// validate through the SDK's own `safeValidateTypes` — the SAME validation the
// browser transport runs on each SSE chunk.
const validateChunk = (value: unknown) => safeValidateTypes({ value, schema: uiMessageChunkSchema });

/*
 * v8.4 — EXHAUSTIVE wire-contract guard for the SSE chat stream.
 *
 * This is the test that closes the class of bug that crashed the chat twice
 * (source-url providerMetadata, then finish.usage): the BE emitted
 * UIMessageChunk frames whose shape the real @ai-sdk zod schema rejects in the
 * browser, aborting the whole stream on the offending frame. The previous
 * tests asserted the BUGGY shapes (BE side) or used `as never` fixtures (FE
 * side) — so nothing validated a real BE frame against the SDK's actual
 * schema.
 *
 * Here we validate EVERY frame type `StreamChunk` / `MessageStreamController`
 * can emit against `uiMessageChunkSchema` — the SAME zod schema the browser
 * transport runs on each SSE chunk. If the BE ever emits a frame the SDK
 * rejects, this fails at build time instead of at the user's first click.
 *
 * The frame fixtures below MIRROR the exact JSON the BE factories produce
 * (app/Ai/StreamChunk.php). The BE-side `StreamChunkTest` pins those exact
 * payloads, so a BE shape change breaks that test (forcing a look here too).
 */

// One of every frame the BE can put on the wire, in the EXACT shape the
// StreamChunk factories emit. Keep in lockstep with app/Ai/StreamChunk.php.
const BE_FRAMES: Record<string, unknown> = {
    start: { type: 'start' },
    'source-url': {
        type: 'source-url',
        sourceId: 'doc-5',
        url: '/app/admin/kb/hr-portal/policies/remote-work-policy.md',
        title: 'remote-work-policy',
        // provenance namespaced under the provider key (record-of-records)
        providerMetadata: {
            askmydocs: { origin: 'primary', headings: ['Remote Work Policy'], chunks_used: 1, source_type: 'markdown' },
        },
    },
    'text-start': { type: 'text-start', id: 'text_abc' },
    'text-delta': { type: 'text-delta', id: 'text_abc', delta: 'Up to 3 days.' },
    'text-end': { type: 'text-end', id: 'text_abc' },
    'data-confidence': { type: 'data-confidence', data: { confidence: 82, tier: 'high' } },
    'data-refusal': { type: 'data-refusal', data: { reason: 'no_relevant_context', body: 'No grounded answer.', hint: null } },
    'data-tool-call': { type: 'data-tool-call', data: { id: 'call_1', name: 'kb.search', status: 'ok' } },
    // finish carries finishReason ONLY — no `usage` (SDK rejects it).
    finish: { type: 'finish', finishReason: 'stop' },
};

describe('SSE wire contract — every BE frame validates against the real @ai-sdk schema', () => {
    for (const [name, frame] of Object.entries(BE_FRAMES)) {
        it(`'${name}' frame is accepted by the real SDK chunk schema`, async () => {
            const result = await validateChunk(frame);
            expect(
                result.success,
                `BE '${name}' frame rejected by the SDK schema → it would crash the stream in the browser. ${
                    result.success ? '' : JSON.stringify(result.error)
                }`,
            ).toBe(true);
        });
    }

    it('the legacy BUGGY shapes are correctly REJECTED (proves the guard has teeth)', async () => {
        // flat providerMetadata (the first crash) and finish.usage (the second)
        expect((await validateChunk({ type: 'source-url', sourceId: 'd', url: '/u', providerMetadata: { origin: 'primary' } })).success).toBe(false);
        expect((await validateChunk({ type: 'finish', finishReason: 'stop', usage: { promptTokens: 0, completionTokens: 0 } })).success).toBe(false);
    });

    it('a full grounded stream is consumed by readUIMessageStream and round-trips citation + text', async () => {
        const onError = vi.fn();
        const order = ['start', 'source-url', 'text-start', 'text-delta', 'text-end', 'finish'];
        const stream = new ReadableStream<UIMessageChunk>({
            start(controller) {
                for (const key of order) {
                    controller.enqueue(BE_FRAMES[key] as UIMessageChunk);
                }
                controller.close();
            },
        });

        let assembled: { parts: unknown[] } | undefined;
        for await (const message of readUIMessageStream({ stream, onError })) {
            assembled = message as unknown as { parts: unknown[] };
        }

        expect(onError).not.toHaveBeenCalled();
        expect(assembled).toBeDefined();
        const citations = getCitations(assembled as never);
        expect(citations).toHaveLength(1);
        expect(citations[0].origin).toBe('primary');
        expect(getTextContent(assembled as never)).toBe('Up to 3 days.');
    });
});
