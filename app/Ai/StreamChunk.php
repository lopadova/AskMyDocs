<?php

namespace App\Ai;

use InvalidArgumentException;
use JsonException;

/**
 * One event in an `AiProviderInterface::chatStream()` generator. Carries the
 * AI SDK SSE protocol's discriminated-union shape — `type` is the
 * discriminator, `payload` is the event-specific data.
 *
 * Wire format (per `docs/v4-platform/PLAN-W3-vercel-chat-migration.md` §6.1):
 * each chunk emits as one SSE message
 *
 *     data: {"type":"<type>","...":"..."}\n\n
 *
 * `toSseFrame()` is the single point that produces that string. Call sites
 * (the streaming controller) MUST go through it to keep the wire format
 * consistent and the protocol-drift test in `MessageStreamControllerTest`
 * meaningful.
 *
 * Use the named-constructor factories below — they enforce the payload
 * shape per type. The struct constructor is public for deserialization
 * tests but not the call-site path.
 */
final readonly class StreamChunk
{
    public const TYPE_TEXT_DELTA = 'text-delta';
    public const TYPE_SOURCE = 'source';
    public const TYPE_DATA_CONFIDENCE = 'data-confidence';
    public const TYPE_DATA_REFUSAL = 'data-refusal';
    public const TYPE_FINISH = 'finish';

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

    public static function textDelta(string $delta): self
    {
        return new self(self::TYPE_TEXT_DELTA, ['textDelta' => $delta]);
    }

    /**
     * Emit a citation reference. `origin` is one of `primary` /
     * `expanded` / `rejected` per `KbSearchService` `SearchResult`
     * groupings.
     */
    public static function source(
        string $sourceId,
        string $title,
        ?string $url,
        string $origin,
    ): self {
        return new self(self::TYPE_SOURCE, [
            'sourceId' => $sourceId,
            'title' => $title,
            'url' => $url,
            'origin' => $origin,
        ]);
    }

    /**
     * `tier` ∈ {high, moderate, low, refused}. `confidence` may be null
     * when the provider didn't return a score (or for refusals where
     * the score is meaningless).
     */
    public static function dataConfidence(?int $confidence, string $tier): self
    {
        return new self(self::TYPE_DATA_CONFIDENCE, [
            'confidence' => $confidence,
            'tier' => $tier,
        ]);
    }

    /**
     * `reason` ∈ {no_relevant_context, llm_self_refusal} per the
     * existing FE refusal rendering. `body` is the user-visible
     * localized text (BE owns localization). `hint` is optional.
     */
    public static function dataRefusal(
        string $reason,
        string $body,
        ?string $hint = null,
    ): self {
        return new self(self::TYPE_DATA_REFUSAL, [
            'reason' => $reason,
            'body' => $body,
            'hint' => $hint,
        ]);
    }

    /**
     * Terminal chunk. `finishReason` mirrors `AiResponse::$finishReason`
     * vocabulary plus `refusal` / `stopped` for the streaming-specific
     * cases.
     */
    public static function finish(
        string $finishReason,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
    ): self {
        return new self(self::TYPE_FINISH, [
            'finishReason' => $finishReason,
            'usage' => [
                'promptTokens' => $promptTokens,
                'completionTokens' => $completionTokens,
            ],
        ]);
    }

    /**
     * Render this chunk as one SSE message. The trailing `\n\n` is
     * mandatory — that's the SSE framing rule. The payload is JSON
     * with `JSON_UNESCAPED_SLASHES` so URLs in `source` chunks stay
     * readable on the wire.
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
