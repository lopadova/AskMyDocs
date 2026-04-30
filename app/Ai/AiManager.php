<?php

namespace App\Ai;

use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\GeminiProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\OpenRouterProvider;
use App\Ai\Providers\RegoloProvider;
use InvalidArgumentException;

class AiManager
{
    /** @var array<string, AiProviderInterface> */
    private array $resolved = [];

    public function provider(?string $name = null): AiProviderInterface
    {
        $name ??= config('ai.default', 'openai');

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    public function embeddingsProvider(): AiProviderInterface
    {
        $name = config('ai.embeddings_provider') ?? config('ai.default', 'openai');
        $provider = $this->provider($name);

        if (! $provider->supportsEmbeddings()) {
            throw new InvalidArgumentException(
                "Provider [{$name}] does not support embeddings. "
                . 'Set AI_EMBEDDINGS_PROVIDER to openai, gemini, or regolo.'
            );
        }

        return $provider;
    }

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        return $this->provider()->chat($systemPrompt, $userMessage, $options);
    }

    /**
     * Multi-turn chat with conversation history.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        return $this->provider()->chatWithHistory($systemPrompt, $messages, $options);
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        return $this->embeddingsProvider()->generateEmbeddings($texts);
    }

    private function resolve(string $name): AiProviderInterface
    {
        $config = config("ai.providers.{$name}");

        if (! $config) {
            throw new InvalidArgumentException("AI provider [{$name}] is not configured.");
        }

        return match ($name) {
            'openai' => new OpenAiProvider($config),
            'anthropic' => new AnthropicProvider($config),
            'gemini' => new GeminiProvider($config),
            'openrouter' => new OpenRouterProvider($config),
            'regolo' => new RegoloProvider($config),
            default => throw new InvalidArgumentException("Unknown AI provider [{$name}]."),
        };
    }
}
