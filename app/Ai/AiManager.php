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
     * embeddings AND `AI_EMBEDDINGS_PROVIDER` is not set.
     *
     * Order is chosen to minimise the risk of a silent dimension mismatch
     * against the default `KB_EMBEDDINGS_DIMENSIONS=1536` pgvector schema
     * (R14 — surface failures loudly; never silently write the wrong shape):
     *
     *   1. openai      — text-embedding-3-small (1536, matches schema default)
     *   2. openrouter  — openai/text-embedding-3-small (1536, matches schema default)
     *   3. regolo      — Qwen3-Embedding-8B (4096, REQUIRES pgvector resize +
     *                    `KB_EMBEDDINGS_DIMENSIONS=4096` already configured)
     *   4. gemini      — text-embedding-004 (768, REQUIRES pgvector resize +
     *                    `KB_EMBEDDINGS_DIMENSIONS=768` already configured)
     *
     * If `KB_EMBEDDINGS_DIMENSIONS` was not migrated in lock-step with the
     * picked provider's default model, ingest writes will fail loudly at the
     * vector-cast layer rather than silently corrupting retrieval. Operators
     * who deliberately run on 4096 / 768 dims must set `AI_EMBEDDINGS_PROVIDER`
     * explicitly to make the choice auditable instead of relying on
     * auto-selection.
     */
    private const EMBEDDINGS_FALLBACK_ORDER = ['openai', 'openrouter', 'regolo', 'gemini'];

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
                    . 'Set AI_EMBEDDINGS_PROVIDER to openai, gemini, regolo, or openrouter.'
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
            // M5 — warning, not info: an auto-selected embeddings provider
            // can have a DIFFERENT vector dimension than the configured
            // pgvector column (e.g. gemini 768 vs the 1536-dim schema). That
            // silently corrupts ingest writes, so this must be visible at
            // the default log level, with the expected dimension surfaced
            // for the operator to cross-check against KB_EMBEDDINGS_DIMENSIONS.
            Log::warning('ai.embeddings_provider auto-selected fallback — verify the vector dimension matches the pgvector column.', [
                'chat_provider' => $defaultName,
                'embeddings_provider' => $fallback->name(),
                'reason' => "chat provider [{$defaultName}] does not support embeddings",
                'expected_dimensions' => config('kb.embeddings_dimensions'),
            ]);

            return $fallback;
        }

        throw new InvalidArgumentException(
            "Provider [{$defaultName}] does not support embeddings and no "
            . 'fallback embeddings provider is configured. '
            . 'Set AI_EMBEDDINGS_PROVIDER=openai|gemini|regolo|openrouter and provide '
            . 'the matching API key (OPENAI_API_KEY, GEMINI_API_KEY, REGOLO_API_KEY, OPENROUTER_API_KEY).'
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
            // Deterministic offline provider — only resolvable when AI_PROVIDER
            // / AI_EMBEDDINGS_PROVIDER name it (E2E / local demo). Never in prod.
            'fake' => new \App\Ai\Providers\FakeProvider($config),
            default => throw new InvalidArgumentException("Unknown AI provider [{$name}]."),
        };
    }
}
