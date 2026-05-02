<?php

namespace App\Ai;

interface AiProviderInterface
{
    /**
     * Single-turn chat completion.
     */
    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse;

    /**
     * Multi-turn chat completion with conversation history.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse;

    /**
     * Multi-turn streaming chat. Yields `StreamChunk` instances over the
     * lifetime of the assistant turn — typically `text-delta` events
     * followed by exactly one `finish` event.
     *
     * Providers without native streaming MUST still implement this
     * method (typically by composing `FallbackStreaming::streamFromChat()`
     * which calls `chatWithHistory()` synchronously and emits the result
     * as a single `text-delta` + `finish`). The `chatStream()` /
     * `chatWithHistory()` parity is what lets every provider plug into
     * the streaming endpoint without each one needing to implement an
     * SSE client.
     *
     * Refusal short-circuit lives in `MessageStreamController` (which
     * decides whether to call `chatStream()` at all based on the search
     * result), NOT inside this interface — the provider only sees a
     * normal turn.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @return \Generator<int, StreamChunk, void, void>
     */
    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator;

    /**
     * Generate embeddings for the given texts.
     *
     * @param  list<string>  $texts
     */
    public function generateEmbeddings(array $texts): EmbeddingsResponse;

    public function name(): string;

    public function supportsEmbeddings(): bool;
}
