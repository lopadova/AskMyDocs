<?php

namespace Tests\Unit\Ai;

use App\Ai\StreamChunk;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v4.0/W3.3 — StreamChunk pinned to the SDK v6 `UIMessageChunk` shape
 * (see `node_modules/ai/dist/index.d.mts:2063`). Wire format MUST
 * match what `@ai-sdk/react`'s `useChat()` parses verbatim — drift
 * between BE emit and FE consume breaks chat in production.
 */
class StreamChunkTest extends TestCase
{
    public function test_start_factory_emits_envelope_opener_with_optional_message_id(): void
    {
        $chunk = StreamChunk::start();

        $this->assertSame(StreamChunk::TYPE_START, $chunk->type);
        $this->assertSame([], $chunk->payload);

        $withId = StreamChunk::start('msg_abc');
        $this->assertSame(['messageId' => 'msg_abc'], $withId->payload);
    }

    public function test_text_start_factory_carries_id_only(): void
    {
        $chunk = StreamChunk::textStart('text_xyz');

        $this->assertSame(StreamChunk::TYPE_TEXT_START, $chunk->type);
        $this->assertSame(['id' => 'text_xyz'], $chunk->payload);
    }

    public function test_text_delta_factory_emits_id_and_delta_per_sdk_v6_shape(): void
    {
        $chunk = StreamChunk::textDelta('text_xyz', 'Hello world');

        $this->assertSame(StreamChunk::TYPE_TEXT_DELTA, $chunk->type);
        // SDK v6 mandates `delta` (NOT `textDelta`) and `id`.
        $this->assertSame(['id' => 'text_xyz', 'delta' => 'Hello world'], $chunk->payload);
    }

    public function test_text_end_factory_carries_id_only(): void
    {
        $chunk = StreamChunk::textEnd('text_xyz');

        $this->assertSame(StreamChunk::TYPE_TEXT_END, $chunk->type);
        $this->assertSame(['id' => 'text_xyz'], $chunk->payload);
    }

    public function test_source_url_factory_includes_required_url_and_optional_title(): void
    {
        $chunk = StreamChunk::sourceUrl(
            sourceId: 'doc-101',
            url: '/app/admin/kb/hr-portal/remote-work-policy',
            title: 'Remote work policy',
        );

        $this->assertSame(StreamChunk::TYPE_SOURCE_URL, $chunk->type);
        $this->assertSame('doc-101', $chunk->payload['sourceId']);
        $this->assertSame('/app/admin/kb/hr-portal/remote-work-policy', $chunk->payload['url']);
        $this->assertSame('Remote work policy', $chunk->payload['title']);
    }

    public function test_source_url_factory_omits_title_when_not_provided(): void
    {
        // SDK shape treats `title` as optional. Omitting it from the
        // payload (instead of sending `null`) keeps the wire compact
        // and matches what the SDK type definition expects.
        $chunk = StreamChunk::sourceUrl('doc-7', '#doc-7');

        $this->assertArrayNotHasKey('title', $chunk->payload);
        $this->assertSame('doc-7', $chunk->payload['sourceId']);
        $this->assertSame('#doc-7', $chunk->payload['url']);
    }

    public function test_data_confidence_factory_wraps_payload_under_data_key(): void
    {
        $chunk = StreamChunk::dataConfidence(82, 'high');

        $this->assertSame(StreamChunk::TYPE_DATA_CONFIDENCE, $chunk->type);
        // SDK `DataUIMessageChunk` shape: `{ type, data: <payload> }`.
        // Flat top-level fields are silently dropped on the FE.
        $this->assertSame(['data' => ['confidence' => 82, 'tier' => 'high']], $chunk->payload);
    }

    public function test_data_confidence_factory_accepts_null_score_for_refusal(): void
    {
        $chunk = StreamChunk::dataConfidence(null, 'refused');

        $this->assertNull($chunk->payload['data']['confidence']);
        $this->assertSame('refused', $chunk->payload['data']['tier']);
    }

    public function test_data_refusal_factory_wraps_reason_body_hint_under_data_key(): void
    {
        $chunk = StreamChunk::dataRefusal(
            reason: 'no_relevant_context',
            body: 'Non ho trovato informazioni rilevanti.',
            hint: 'Riformula la domanda con termini più specifici.',
        );

        $this->assertSame(StreamChunk::TYPE_DATA_REFUSAL, $chunk->type);
        $this->assertSame('no_relevant_context', $chunk->payload['data']['reason']);
        $this->assertSame('Non ho trovato informazioni rilevanti.', $chunk->payload['data']['body']);
        $this->assertSame('Riformula la domanda con termini più specifici.', $chunk->payload['data']['hint']);
    }

    public function test_data_refusal_factory_hint_is_optional(): void
    {
        $chunk = StreamChunk::dataRefusal('llm_self_refusal', 'I cannot answer that.');

        $this->assertNull($chunk->payload['data']['hint']);
    }

    public function test_finish_factory_carries_finish_reason_and_usage(): void
    {
        $chunk = StreamChunk::finish(
            finishReason: 'stop',
            promptTokens: 1234,
            completionTokens: 56,
        );

        $this->assertSame(StreamChunk::TYPE_FINISH, $chunk->type);
        $this->assertSame('stop', $chunk->payload['finishReason']);
        $this->assertSame(['promptTokens' => 1234, 'completionTokens' => 56], $chunk->payload['usage']);
    }

    public function test_finish_factory_accepts_null_token_counts(): void
    {
        // Provider didn't return usage (e.g. some open-source models).
        // Frame must still serialize cleanly.
        $chunk = StreamChunk::finish('stop');

        $this->assertNull($chunk->payload['usage']['promptTokens']);
        $this->assertNull($chunk->payload['usage']['completionTokens']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validFinishReasonProvider(): iterable
    {
        yield 'stop' => ['stop'];
        yield 'length' => ['length'];
        yield 'content-filter' => ['content-filter'];
        yield 'tool-calls' => ['tool-calls'];
        yield 'error' => ['error'];
        yield 'other' => ['other'];
    }

    #[DataProvider('validFinishReasonProvider')]
    public function test_finish_factory_accepts_every_sdk_union_member(string $reason): void
    {
        $chunk = StreamChunk::finish($reason);

        $this->assertSame($reason, $chunk->payload['finishReason']);
    }

    public function test_finish_factory_rejects_finish_reason_outside_sdk_union(): void
    {
        // `'refusal'` was the W3.1 application-level value — under SDK
        // v6 the enum no longer contains it, so the factory must
        // reject it loudly. Ditto any provider-specific raw string
        // (`'end_turn'`, `'max_tokens'`, …) — callers MUST normalize
        // via `StreamChunk::normalizeFinishReason()` first.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/finishReason must be one of/');
        StreamChunk::finish('refusal');
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function finishReasonNormalizationProvider(): iterable
    {
        yield 'null defaults to stop' => [null, 'stop'];
        yield 'in-union stop passes through' => ['stop', 'stop'];
        yield 'in-union length passes through' => ['length', 'length'];
        yield 'anthropic end_turn maps to stop' => ['end_turn', 'stop'];
        yield 'anthropic max_tokens maps to length' => ['max_tokens', 'length'];
        yield 'anthropic tool_use maps to tool-calls' => ['tool_use', 'tool-calls'];
        yield 'openai content_filter maps to content-filter' => ['content_filter', 'content-filter'];
        yield 'openai tool_calls underscore maps to tool-calls' => ['tool_calls', 'tool-calls'];
        yield 'gemini STOP maps to stop' => ['STOP', 'stop'];
        yield 'gemini MAX_TOKENS maps to length' => ['MAX_TOKENS', 'length'];
        yield 'gemini SAFETY maps to content-filter' => ['SAFETY', 'content-filter'];
        yield 'gemini RECITATION maps to content-filter' => ['RECITATION', 'content-filter'];
        yield 'unknown provider value defaults to stop' => ['weird_unknown_reason', 'stop'];
    }

    #[DataProvider('finishReasonNormalizationProvider')]
    public function test_normalize_finish_reason_maps_provider_vocabularies_to_sdk_union(
        ?string $providerReason,
        string $expected,
    ): void
    {
        $this->assertSame($expected, StreamChunk::normalizeFinishReason($providerReason));
    }

    public function test_to_sse_frame_emits_canonical_data_prefix_and_double_newline_terminator(): void
    {
        $chunk = StreamChunk::textDelta('text_xyz', 'chunk one');

        // The SSE framing rule: every event MUST end with `\n\n`.
        // Without the terminator the FE EventSource won't dispatch.
        $frame = $chunk->toSseFrame();

        $this->assertStringStartsWith('data: ', $frame);
        $this->assertStringEndsWith("\n\n", $frame);
    }

    public function test_to_sse_frame_payload_is_valid_json_with_unescaped_slashes(): void
    {
        $chunk = StreamChunk::sourceUrl(
            sourceId: 'doc-101',
            url: '/app/admin/kb/hr-portal/remote-work-policy',
            title: 'Remote work policy',
        );

        $frame = $chunk->toSseFrame();
        $jsonPart = trim(substr($frame, strlen('data: ')));
        $decoded = json_decode($jsonPart, associative: true, flags: JSON_THROW_ON_ERROR);

        // Type discriminator survives serialization.
        $this->assertSame('source-url', $decoded['type']);
        // Payload keys merged at top level (not nested under "payload").
        $this->assertSame('Remote work policy', $decoded['title']);
        // URL retains slashes — the FE receives a usable href without
        // unescaping `\/` sequences.
        $this->assertStringContainsString('/app/admin/kb/hr-portal/remote-work-policy', $jsonPart);
        $this->assertStringNotContainsString('\\/', $jsonPart);
    }

    public function test_to_sse_frame_handles_unicode_payloads_without_double_encoding(): void
    {
        $chunk = StreamChunk::dataRefusal(
            reason: 'no_relevant_context',
            body: 'Non ho trovato informazioni rilevanti — riformula la domanda.',
        );

        $frame = $chunk->toSseFrame();

        // JSON_UNESCAPED_UNICODE keeps the em-dash + accents as raw
        // UTF-8 bytes on the wire instead of as `—` escape
        // sequences. Both forms are valid JSON and the FE decoder
        // handles either, but raw UTF-8 keeps the payload readable
        // in dev tools / logs / golden fixture diffs.
        $this->assertStringContainsString('—', $frame);
        $this->assertStringContainsString('riformula', $frame);
    }

    public function test_to_array_merges_type_and_payload_at_top_level(): void
    {
        // The wire shape (top-level `type` + `payload` keys merged) MUST
        // match toSseFrame output so a test that decodes a frame and a
        // test that calls toArray see identical data — drift between
        // the two is a protocol bug. Note that data-confidence carries
        // its payload nested under `data` per the SDK shape.
        $chunk = StreamChunk::dataConfidence(82, 'high');

        $expected = [
            'type' => 'data-confidence',
            'data' => ['confidence' => 82, 'tier' => 'high'],
        ];
        $this->assertSame($expected, $chunk->toArray());
    }

    public function test_constructor_rejects_payload_with_reserved_type_key(): void
    {
        // Defensive guard against a payload that smuggles in a `type`
        // key — without the guard, `[...$this->payload]` in toSseFrame
        // / toArray would silently override the discriminator and
        // emit an invalid frame. The guard catches the programming
        // mistake at construction time, where the failure mode is
        // unambiguous.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/payload cannot contain a \'type\' key/');
        new StreamChunk('text-delta', ['type' => 'oops', 'delta' => 'hi']);
    }

    public function test_to_sse_frame_throws_on_non_serializable_payload(): void
    {
        // Programming-mistake guard: if a future factory accidentally
        // includes a non-encodable value (recursion, bound resource,
        // etc.), surface immediately at the call site instead of
        // emitting a malformed event downstream.
        $chunk = new StreamChunk('text-delta', ['id' => 'x', 'delta' => "\xB1\x31"]); // invalid UTF-8

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/StreamChunk\[text-delta\] payload is not JSON-encodable/');
        $chunk->toSseFrame();
    }

    /**
     * @return iterable<string, array{StreamChunk, string}>
     */
    public static function frameDiscriminatorProvider(): iterable
    {
        yield 'start' => [StreamChunk::start(), 'start'];
        yield 'text-start' => [StreamChunk::textStart('text_x'), 'text-start'];
        yield 'text-delta' => [StreamChunk::textDelta('text_x', 'hi'), 'text-delta'];
        yield 'text-end' => [StreamChunk::textEnd('text_x'), 'text-end'];
        yield 'source-url' => [StreamChunk::sourceUrl('s', '/u', 't'), 'source-url'];
        yield 'data-confidence' => [StreamChunk::dataConfidence(50, 'moderate'), 'data-confidence'];
        yield 'data-refusal' => [StreamChunk::dataRefusal('llm_self_refusal', 'no'), 'data-refusal'];
        yield 'finish' => [StreamChunk::finish('stop'), 'finish'];
    }

    #[DataProvider('frameDiscriminatorProvider')]
    public function test_each_chunk_kind_emits_correct_type_discriminator_in_frame(StreamChunk $chunk, string $expectedType): void
    {
        $frame = $chunk->toSseFrame();
        $jsonPart = trim(substr($frame, strlen('data: ')));
        $decoded = json_decode($jsonPart, associative: true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expectedType, $decoded['type']);
    }
}
