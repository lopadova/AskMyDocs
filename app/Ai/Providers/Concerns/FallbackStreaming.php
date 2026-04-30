<?php

namespace App\Ai\Providers\Concerns;

use App\Ai\AiResponse;
use App\Ai\StreamChunk;
use Generator;

/**
 * Default `chatStream()` implementation for providers without native
 * streaming. Calls `chatWithHistory()` synchronously and replays the
 * full assistant content as a single `text-delta` + `finish` pair.
 *
 * Why this exists:
 *   - The `MessageStreamController` SSE route MUST work for every
 *     configured provider, even ones (Anthropic / Gemini / OpenRouter
 *     today) where we haven't wired native streaming. Without this
 *     fallback the controller would have to branch per provider, or
 *     the route would 500 when a non-streaming provider is selected.
 *   - The trade-off is no token-by-token render for fallback providers
 *     — the user sees the full response land in one big delta. The FE
 *     `useChat()` hook handles that case correctly (it just renders
 *     the whole text at once, same as the synchronous endpoint).
 *
 * Composition: providers `use FallbackStreaming;` and call
 * `$this->streamFromChat($systemPrompt, $messages, $options)` from
 * inside their `chatStream()` method body. Native-streaming providers
 * (Regolo via SDK, OpenAI via HTTP SSE) override `chatStream()`
 * entirely and don't use this trait.
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

        if ($response->content !== '') {
            yield StreamChunk::textDelta($response->content);
        }

        yield StreamChunk::finish(
            finishReason: $response->finishReason ?? 'stop',
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
        );
    }
}
