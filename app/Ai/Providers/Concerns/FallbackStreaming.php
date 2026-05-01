<?php

namespace App\Ai\Providers\Concerns;

use App\Ai\AiResponse;
use App\Ai\StreamChunk;
use Generator;

/**
 * Default `chatStream()` implementation for providers without native
 * streaming. Calls `chatWithHistory()` synchronously and replays the
 * full assistant content as a single text envelope (`text-start` →
 * one `text-delta` → `text-end`) followed by a `finish` chunk —
 * matching the SDK v6 `UIMessageChunk` shape `useChat()` parses.
 *
 * Why this exists:
 *   - The `MessageStreamController` SSE route MUST work for every
 *     configured provider, even ones (Anthropic / Gemini / OpenRouter
 *     today) where we haven't wired native streaming. Without this
 *     fallback the controller would have to branch per provider, or
 *     the route would 500 when a non-streaming provider is selected.
 *   - The trade-off is no token-by-token render for fallback providers
 *     — the user sees the full response land in one big delta. The FE
 *     `useChat()` hook handles that case correctly (it renders the
 *     whole text at once, same UX as the synchronous endpoint).
 *
 * Composition: providers `use FallbackStreaming;` and call
 * `$this->streamFromChat($systemPrompt, $messages, $options)` from
 * inside their `chatStream()` method body. Native-streaming providers
 * (Regolo via SDK, OpenAI via HTTP SSE — planned follow-ups) override
 * `chatStream()` entirely and don't use this trait, but MUST emit the
 * same `text-start` / `text-delta` / `text-end` / `finish` envelope so
 * the wire shape stays uniform across providers.
 */
trait FallbackStreaming
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @return Generator<int, StreamChunk, void, void>
     */
    protected function streamFromChat(
        string $systemPrompt,
        array $messages,
        array $options = [],
    ): Generator {
        /** @var AiResponse $response */
        $response = $this->chatWithHistory($systemPrompt, $messages, $options);

        // Single id covers the whole text envelope — SDK v6 stitches
        // every `text-delta` carrying this id back into one rendered
        // text part. `bin2hex(random_bytes(8))` is 16 hex chars: short
        // on the wire, collision-free in practice, no Laravel facade
        // dependency (the trait is unit-testable without booting the
        // container).
        $textId = 'text_' . bin2hex(random_bytes(8));

        if ($response->content !== '') {
            yield StreamChunk::textStart($textId);
            yield StreamChunk::textDelta($textId, $response->content);
            yield StreamChunk::textEnd($textId);
        }

        yield StreamChunk::finish(
            // Map provider-specific reasons (`end_turn`, `max_tokens`,
            // `STOP`, …) to the SDK v6 `FinishReason` union. Without
            // this normalization `StreamChunk::finish()` throws on
            // anything outside the union — see the centralized map
            // in `StreamChunk::normalizeFinishReason()`.
            finishReason: StreamChunk::normalizeFinishReason($response->finishReason),
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
        );
    }
}
