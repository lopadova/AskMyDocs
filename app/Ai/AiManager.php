<?php

namespace App\Ai;

use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\GeminiProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\OpenRouterProvider;
use App\Ai\Providers\RegoloProvider;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AiManager
{
    /**
     * Auto-fallback search order when the chat provider doesn't support
     * embeddings AND `AI_EMBEDDINGS_PROVIDER` is not set. Regolo first
     * because it ships in-house with EU-residency Qwen3-Embedding-8B;
     * OpenAI second (most universally configured); Gemini third;
     * OpenRouter last (only since Oct 2025 with qwen/qwen3-embedding-4b).
     */
    private const EMBEDDINGS_FALLBACK_ORDER = ['regolo', 'openai', 'gemini', 'openrouter'];

    /** @var array<string, AiProviderInterface> */
    private array $resolved = [];

    public function provider(?string $name = null): AiProviderInterface
    {
        $name ??= config('ai.default', 'openai');

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    public function embeddingsProvider(): AiProviderInterface
    {
        $explicit = config('ai.embeddings_provider');

        if ($explicit !== null && $explicit !== '') {
            $provider = $this->provider($explicit);

            if (! $provider->supportsEmbeddings()) {
                throw new InvalidArgumentException(
                    "Provider [{$explicit}] does not support embeddings. "
                    . 'Set AI_EMBEDDINGS_PROVIDER to openai, gemini, or regolo.'
                );
            }

            return $provider;
        }

        $defaultName = config('ai.default', 'openai');
        $defaultProvider = $this->provider($defaultName);

        if ($defaultProvider->supportsEmbeddings()) {
            return $defaultProvider;
        }

        $fallback = $this->autoSelectEmbeddingsProvider();

        if ($fallback !== null) {
            Log::info('ai.embeddings_provider auto-selected fallback', [
                'chat_provider' => $defaultName,
                'embeddings_provider' => $fallback->name(),
                'reason' => "chat provider [{$defaultName}] does not support embeddings",
            ]);

            return $fallback;
        }

        throw new InvalidArgumentException(
            "Provider [{$defaultName}] does not support embeddings and no "
            . 'fallback embeddings provider is configured. '
            . 'Set AI_EMBEDDINGS_PROVIDER=openai|gemini|regolo and provide '
            . 'the matching API key (OPENAI_API_KEY, GEMINI_API_KEY, REGOLO_API_KEY).'
        );
    }

    private function autoSelectEmbeddingsProvider(): ?AiProviderInterface
    {
        foreach (self::EMBEDDINGS_FALLBACK_ORDER as $name) {
            if (! $this->hasApiKey($name)) {
                continue;
            }

            $provider = $this->provider($name);

            if ($provider->supportsEmbeddings()) {
                return $provider;
            }
        }

        return null;
    }

    private function hasApiKey(string $provider): bool
    {
        $key = match ($provider) {
            'openai' => config('ai.providers.openai.api_key'),
            'gemini' => config('ai.providers.gemini.api_key'),
            'regolo' => config('ai.providers.regolo.key'),
            'openrouter' => config('ai.providers.openrouter.api_key'),
            default => null,
        };

        return is_string($key) && $key !== '';
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

    /**
     * Multi-turn streaming chat. See `AiProviderInterface::chatStream()`
     * for the chunk-event protocol.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @return \Generator<int, StreamChunk, void, void>
     */
    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
    {
        return $this->provider()->chatStream($systemPrompt, $messages, $options);
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
