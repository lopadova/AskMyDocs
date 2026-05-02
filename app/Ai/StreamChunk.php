<?php

namespace App\Ai;

use InvalidArgumentException;
use JsonException;

/**
 * One event in an `AiProviderInterface::chatStream()` generator. Carries
 * the SDK v6 `UIMessageChunk` discriminated-union shape so
 * `@ai-sdk/react`'s `useChat()` can parse the wire directly without a
 * custom transport adapter on the FE.
 *
 * Wire format (one SSE message per chunk):
 *
 *     data: {"type":"start"}\n\n
 *     data: {"type":"source-url","sourceId":"doc-1","url":"...","title":"..."}\n\n
 *     data: {"type":"text-start","id":"text_xxx"}\n\n
 *     data: {"type":"text-delta","id":"text_xxx","delta":"Hello"}\n\n
 *     data: {"type":"text-end","id":"text_xxx"}\n\n
 *     data: {"type":"data-confidence","data":{"confidence":82,"tier":"high"}}\n\n
 *     data: {"type":"finish","finishReason":"stop","usage":{"promptTokens":...,"completionTokens":...}}\n\n
 *
 * Refusal stream variant (no text envelope, refusal payload only):
 *
 *     data: {"type":"start"}\n\n
 *     data: {"type":"data-refusal","data":{"reason":"...","body":"...","hint":null}}\n\n
 *     data: {"type":"data-confidence","data":{"confidence":null,"tier":"refused"}}\n\n
 *     data: {"type":"finish","finishReason":"stop","usage":{"promptTokens":0,"completionTokens":0}}\n\n
 *
 * The shape mirrors `UIMessageChunk` in `node_modules/ai/dist/index.d.mts`
 * — `text-delta` carries `id` + `delta` (not `textDelta`); `source-url`
 * mandates `url`; `data-*` chunks wrap their payload under a `data`
 * sub-object; `finish.finishReason` is constrained to the SDK union
 * `'stop' | 'length' | 'content-filter' | 'tool-calls' | 'error' |
 * 'other'`. Anything outside the union is rejected at the factory.
 *
 * `toSseFrame()` is the single point that produces the wire string.
 * Call sites (the streaming controller, the FallbackStreaming trait,
 * native-streaming providers) MUST go through it so the wire format
 * stays consistent and the protocol-drift test in
 * `MessageStreamControllerTest::test_8_sse_wire_format_is_stable`
 * remains meaningful.
 *
 * Use the named-constructor factories below — they enforce the payload
 * shape per type. The struct constructor is public for deserialization
 * tests but not the call-site path.
 */
final readonly class StreamChunk
{
    public const TYPE_START = 'start';
    public const TYPE_TEXT_START = 'text-start';
    public const TYPE_TEXT_DELTA = 'text-delta';
    public const TYPE_TEXT_END = 'text-end';
    public const TYPE_SOURCE_URL = 'source-url';
    public const TYPE_DATA_CONFIDENCE = 'data-confidence';
    public const TYPE_DATA_REFUSAL = 'data-refusal';
    public const TYPE_FINISH = 'finish';

    /**
     * SDK v6 `FinishReason` union, copied from
     * `node_modules/ai/dist/index.d.mts` line 108. The factory rejects
     * any other value so application-level categorization (refusal,
     * cancelled, etc.) cannot leak onto the wire and break the SDK
     * stream parser.
     */
    public const FINISH_REASONS = [
        'stop',
        'length',
        'content-filter',
        'tool-calls',
        'error',
        'other',
    ];

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException If `$payload` contains a `type`
     *         key — that key is reserved for the discriminator the
     *         constructor itself sets and would silently override
     *         `$this->type` in `toArray()` / `toSseFrame()` via the
     *         `[...$this->payload]` spread, producing an invalid frame.
     *         Use the named-constructor factories below for the
     *         normal call path.
     */
    public function __construct(
        public string $type,
        public array $payload,
    ) {
        if (array_key_exists('type', $payload)) {
            // get_debug_type() is safe for any value (object, array,
            // resource, scalar) — the previous string interpolation
            // could fault when `$payload['type']` was non-stringable
            // (array/object), turning this defensive guard into a
            // worse failure than the one it was meant to catch.
            $debugType = get_debug_type($payload['type']);
            throw new InvalidArgumentException(
                "StreamChunk payload cannot contain a 'type' key (reserved for the discriminator); got payload with type of {$debugType}",
            );
        }
    }

    /**
     * Stream envelope opener — SDK v6 `UIMessageChunk` requires a
     * `start` chunk before any text/data parts so `useChat()` knows
     * a new assistant turn has begun. The optional `messageId` binds
     * the FE-side `UIMessage` to a server-generated identifier; the
     * AskMyDocs streaming controller doesn't surface a stable id at
     * stream-open time today (the assistant Message row is created
     * after the stream completes), so the field is omitted.
     */
    public static function start(?string $messageId = null): self
    {
        $payload = [];
        if ($messageId !== null) {
            $payload['messageId'] = $messageId;
        }

        return new self(self::TYPE_START, $payload);
    }

    /**
     * Open a text part. Every `text-delta` chunk in the same logical
     * text block MUST carry the same `$id` so the SDK can stitch the
     * deltas back into one rendered string. `text-end` with the same
     * id closes the block.
     */
    public static function textStart(string $id): self
    {
        return new self(self::TYPE_TEXT_START, ['id' => $id]);
    }

    /**
     * Append a delta to the text block opened by `textStart($id)`.
     * The SDK shape uses `delta` (not `textDelta`) — see
     * `UIMessageChunk` in `ai/dist/index.d.mts`.
     */
    public static function textDelta(string $id, string $delta): self
    {
        return new self(self::TYPE_TEXT_DELTA, [
            'id' => $id,
            'delta' => $delta,
        ]);
    }

    /**
     * Close a text block. Required by SDK v6 — without it `useChat()`
     * keeps the part in a "streaming" state and never marks it final.
     */
    public static function textEnd(string $id): self
    {
        return new self(self::TYPE_TEXT_END, ['id' => $id]);
    }

    /**
     * Citation reference per SDK v6 `source-url` chunk shape. `url`
     * is mandatory on the SDK side — callers that don't have a real
     * canonical URL should pass an in-app fallback (`#doc-X`) so the
     * FE chip still renders, never `null`. `title` is optional per
     * the SDK; we pass it whenever the upstream citation has one.
     *
     * The `origin` grouping concept (primary / expanded / rejected)
     * is intentionally dropped on the wire — the SDK's `source-url`
     * type doesn't carry it, and the FE adapter
     * (`coerceCitationOrigin` in `message-shape-adapters.ts`) defaults
     * to `'primary'` for any source-url chunk. Re-introducing origin
     * would require a custom data-* chunk and a parallel FE adapter
     * surface; not worth the complexity for a UI grouping that today
     * is mostly cosmetic.
     */
    public static function sourceUrl(
        string $sourceId,
        string $url,
        ?string $title = null,
    ): self {
        $payload = [
            'sourceId' => $sourceId,
            'url' => $url,
        ];
        if ($title !== null) {
            $payload['title'] = $title;
        }

        return new self(self::TYPE_SOURCE_URL, $payload);
    }

    /**
     * `tier` ∈ {high, moderate, low, refused}. `confidence` may be null
     * when the provider didn't return a score (or for refusals where
     * the score is meaningless).
     *
     * SDK v6 `data-*` chunks wrap their payload under a `data` key
     * — the SDK's `DataUIPart` type defines
     * `{ type: 'data-<NAME>', data: <T> }` and the parser populates
     * `part.data.<field>` only. Flat top-level fields are silently
     * dropped on the FE side.
     */
    public static function dataConfidence(?int $confidence, string $tier): self
    {
        return new self(self::TYPE_DATA_CONFIDENCE, [
            'data' => [
                'confidence' => $confidence,
                'tier' => $tier,
            ],
        ]);
    }

    /**
     * `reason` ∈ {no_relevant_context, llm_self_refusal} per the
     * existing FE refusal rendering. `body` is the user-visible
     * localized text (BE owns localization — R24). `hint` is optional.
     *
     * Same `data` envelope rule as `dataConfidence()` — the SDK's
     * `DataUIPart` shape demands the payload under `data`.
     */
    public static function dataRefusal(
        string $reason,
        string $body,
        ?string $hint = null,
    ): self {
        return new self(self::TYPE_DATA_REFUSAL, [
            'data' => [
                'reason' => $reason,
                'body' => $body,
                'hint' => $hint,
            ],
        ]);
    }

    /**
     * Terminal chunk. `finishReason` MUST be one of
     * {@see self::FINISH_REASONS} per SDK v6 `FinishReason` union.
     * Refusal turns (whether retrieval-side or LLM self-refusal)
     * collapse to `'stop'` on the wire — refusal is an
     * application-level categorization carried on the persisted
     * Message row's `refusal_reason` column AND in the
     * `data-refusal` stream chunk; surfacing it on `finish` would
     * fall outside the SDK union and the FE stream parser would
     * reject the chunk.
     *
     * @throws InvalidArgumentException When `$finishReason` is not in
     *         {@see self::FINISH_REASONS}.
     */
    public static function finish(
        string $finishReason = 'stop',
        ?int $promptTokens = null,
        ?int $completionTokens = null,
    ): self {
        if (! in_array($finishReason, self::FINISH_REASONS, strict: true)) {
            throw new InvalidArgumentException(sprintf(
                'StreamChunk::finish() finishReason must be one of [%s]; got "%s".',
                implode(', ', self::FINISH_REASONS),
                $finishReason,
            ));
        }

        return new self(self::TYPE_FINISH, [
            'finishReason' => $finishReason,
            'usage' => [
                'promptTokens' => $promptTokens,
                'completionTokens' => $completionTokens,
            ],
        ]);
    }

    /**
     * Map a provider-specific finish reason (`end_turn`, `max_tokens`,
     * `tool_use`, etc.) to the SDK v6 `FinishReason` union. Returns
     * `'stop'` for any unrecognized / null input — the safe default
     * for an end-of-stream marker that doesn't break the FE.
     *
     * Centralized here so providers and the streaming controller
     * agree on the mapping. Adding a new alias = adding one row.
     */
    public static function normalizeFinishReason(?string $providerReason): string
    {
        if ($providerReason === null) {
            return 'stop';
        }
        if (in_array($providerReason, self::FINISH_REASONS, strict: true)) {
            return $providerReason;
        }

        return match ($providerReason) {
            // Anthropic / Bedrock vocabulary
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'tool_use' => 'tool-calls',
            // OpenAI-style underscore variants
            'content_filter' => 'content-filter',
            'tool_calls' => 'tool-calls',
            // Gemini vocabulary
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY' => 'content-filter',
            'RECITATION' => 'content-filter',
            default => 'stop',
        };
    }

    /**
     * Render this chunk as one SSE message. The trailing `\n\n` is
     * mandatory — that's the SSE framing rule. The payload is JSON
     * with `JSON_UNESCAPED_SLASHES` so URLs in `source-url` chunks
     * stay readable on the wire.
     *
     * @throws InvalidArgumentException when the payload contains a
     *   value json_encode cannot serialize (catches programming
     *   mistakes early).
     */
    public function toSseFrame(): string
    {
        try {
            $json = json_encode(
                ['type' => $this->type, ...$this->payload],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                "StreamChunk[{$this->type}] payload is not JSON-encodable: {$e->getMessage()}",
                previous: $e,
            );
        }

        return "data: {$json}\n\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => $this->type, ...$this->payload];
    }
}
