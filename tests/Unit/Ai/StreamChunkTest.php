<?php

namespace Tests\Unit\Ai;

use App\Ai\StreamChunk;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StreamChunkTest extends TestCase
{
    public function test_text_delta_factory_produces_canonical_payload(): void
    {
        $chunk = StreamChunk::textDelta('Hello world');

        $this->assertSame(StreamChunk::TYPE_TEXT_DELTA, $chunk->type);
        $this->assertSame(['textDelta' => 'Hello world'], $chunk->payload);
    }

    public function test_source_factory_includes_origin_grouping(): void
    {
        $chunk = StreamChunk::source(
            sourceId: 'doc-101',
            title: 'Remote work policy',
            url: '/app/admin/kb/hr-portal/remote-work-policy',
            origin: 'primary',
        );

        $this->assertSame(StreamChunk::TYPE_SOURCE, $chunk->type);
        $this->assertSame('doc-101', $chunk->payload['sourceId']);
        $this->assertSame('Remote work policy', $chunk->payload['title']);
        $this->assertSame('/app/admin/kb/hr-portal/remote-work-policy', $chunk->payload['url']);
        $this->assertSame('primary', $chunk->payload['origin']);
    }

    public function test_source_factory_accepts_null_url_for_synthesized_sources(): void
    {
        // Some sources (e.g. synthetic citations from rejected-approach
        // injection) have no canonical URL; the chunk must still emit
        // cleanly for the FE to render the chip without an href.
        $chunk = StreamChunk::source('rej-7', 'Rejected approach: caching v1', null, 'rejected');

        $this->assertNull($chunk->payload['url']);
    }

    public function test_data_confidence_factory_carries_tier(): void
    {
        $chunk = StreamChunk::dataConfidence(82, 'high');

        $this->assertSame(StreamChunk::TYPE_DATA_CONFIDENCE, $chunk->type);
        $this->assertSame(82, $chunk->payload['confidence']);
        $this->assertSame('high', $chunk->payload['tier']);
    }

    public function test_data_confidence_factory_accepts_null_score_for_refusal(): void
    {
        $chunk = StreamChunk::dataConfidence(null, 'refused');

        $this->assertNull($chunk->payload['confidence']);
        $this->assertSame('refused', $chunk->payload['tier']);
    }

    public function test_data_refusal_factory_carries_reason_body_and_optional_hint(): void
    {
        $chunk = StreamChunk::dataRefusal(
            reason: 'no_relevant_context',
            body: 'Non ho trovato informazioni rilevanti.',
            hint: 'Riformula la domanda con termini più specifici.',
        );

        $this->assertSame(StreamChunk::TYPE_DATA_REFUSAL, $chunk->type);
        $this->assertSame('no_relevant_context', $chunk->payload['reason']);
        $this->assertSame('Non ho trovato informazioni rilevanti.', $chunk->payload['body']);
        $this->assertSame('Riformula la domanda con termini più specifici.', $chunk->payload['hint']);
    }

    public function test_data_refusal_factory_hint_is_optional(): void
    {
        $chunk = StreamChunk::dataRefusal('llm_self_refusal', 'I cannot answer that.');

        $this->assertNull($chunk->payload['hint']);
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

    public function test_to_sse_frame_emits_canonical_data_prefix_and_double_newline_terminator(): void
    {
        $chunk = StreamChunk::textDelta('chunk one');

        // The SSE framing rule: every event MUST end with `\n\n`.
        // Without the terminator the FE EventSource won't dispatch.
        $frame = $chunk->toSseFrame();

        $this->assertStringStartsWith('data: ', $frame);
        $this->assertStringEndsWith("\n\n", $frame);
    }

    public function test_to_sse_frame_payload_is_valid_json_with_unescaped_slashes(): void
    {
        $chunk = StreamChunk::source(
            sourceId: 'doc-101',
            title: 'Remote work policy',
            url: '/app/admin/kb/hr-portal/remote-work-policy',
            origin: 'primary',
        );

        $frame = $chunk->toSseFrame();
        $jsonPart = trim(substr($frame, strlen('data: ')));
        $decoded = json_decode($jsonPart, associative: true, flags: JSON_THROW_ON_ERROR);

        // Type discriminator survives serialization.
        $this->assertSame('source', $decoded['type']);
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

        // JSON_UNESCAPED_UNICODE keeps the em-dash + accents readable
        // on the wire; without it the FE sees `—` literals and
        // has to double-decode.
        $this->assertStringContainsString('—', $frame);
        $this->assertStringContainsString('riformula', $frame);
    }

    public function test_to_array_merges_type_and_payload_at_top_level(): void
    {
        // The wire shape (top-level `type` + `payload` keys merged) MUST
        // match toSseFrame output so a test that decodes a frame and a
        // test that calls toArray see identical data — drift between
        // the two is a protocol bug.
        $chunk = StreamChunk::dataConfidence(82, 'high');

        $expected = ['type' => 'data-confidence', 'confidence' => 82, 'tier' => 'high'];
        $this->assertSame($expected, $chunk->toArray());
    }

    public function test_to_sse_frame_throws_on_non_serializable_payload(): void
    {
        // Programming-mistake guard: if a future factory accidentally
        // includes a non-encodable value (recursion, bound resource,
        // etc.), surface immediately at the call site instead of
        // emitting a malformed event downstream.
        $chunk = new StreamChunk('text-delta', ['textDelta' => "\xB1\x31"]); // invalid UTF-8

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/StreamChunk\[text-delta\] payload is not JSON-encodable/');
        $chunk->toSseFrame();
    }

    /**
     * @return iterable<string, array{StreamChunk, string}>
     */
    public static function frameDiscriminatorProvider(): iterable
    {
        yield 'text-delta' => [StreamChunk::textDelta('hi'), 'text-delta'];
        yield 'source' => [StreamChunk::source('s', 't', 'u', 'primary'), 'source'];
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
