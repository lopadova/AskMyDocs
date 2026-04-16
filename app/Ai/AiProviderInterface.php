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
     * Generate embeddings for the given texts.
     *
     * @param  list<string>  $texts
     */
    public function generateEmbeddings(array $texts): EmbeddingsResponse;

    public function name(): string;

    public function supportsEmbeddings(): bool;
}
