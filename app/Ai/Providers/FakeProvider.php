<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Concerns\FallbackStreaming;

/**
 * Deterministic, offline AI provider for end-to-end tests (and local
 * demos). Makes NO external HTTP calls — chat answers are canned and
 * embeddings are a constant unit vector.
 *
 * Why it exists: the Playwright browser E2E (`chat-stream-browser.spec.ts`)
 * must drive the REAL `/messages/stream` SSE through the REAL `@ai-sdk`
 * transport in the browser — that is the only layer that validates each
 * UIMessageChunk against the SDK zod schema (the layer where the v8.4
 * source-url / finish wire-format crashes actually fired). To do that
 * deterministically in CI — without a live LLM, without an API key, and with
 * GUARANTEED citations so the `source-url` frame is exercised — the back-end
 * needs a provider that:
 *
 *   - streams a fixed, non-empty answer (so text-* + finish frames flow), and
 *   - returns a CONSTANT embedding vector for every input, so every ingested
 *     chunk and every query map to the same vector → cosine 1.0 → retrieval
 *     always returns the seeded chunk → the controller always emits a
 *     `source-url` citation frame.
 *
 * Selected by pointing `ai.default` / `ai.embeddings_provider` at 'fake'
 * — the E2E/local path does that via `AI_PROVIDER=fake` +
 * `AI_EMBEDDINGS_PROVIDER=fake` (see playwright.config.ts webServer env).
 * NEVER usable in production: AiManager::resolveFakeProvider() throws unless
 * the app is in the testing or local environment, regardless of config.
 */
final class FakeProvider implements AiProviderInterface
{
    use FallbackStreaming;

    /** Canned grounded answer streamed for every chat turn. */
    public const ANSWER = 'Based on the knowledge base, employees may work remotely up to 3 days per week with manager approval.';

    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly array $config = []) {}

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        return $this->chatWithHistory($systemPrompt, [['role' => 'user', 'content' => $userMessage]], $options);
    }

    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        return new AiResponse(
            content: self::ANSWER,
            provider: 'fake',
            model: $this->modelName('chat_model'),
            promptTokens: 11,
            completionTokens: 17,
            totalTokens: 28,
            finishReason: 'stop',
        );
    }

    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
    {
        yield from $this->streamFromChat($systemPrompt, $messages, $options);
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        $dimensions = (int) ($this->config['dimensions'] ?? config('kb.embeddings_dimensions', 1536));

        // Fail loudly on a misconfigured dimension rather than silently
        // clamping with max(1, …) — a 0/negative value means a broken
        // KB_EMBEDDINGS_DIMENSIONS, which would otherwise surface much later
        // as a confusing pgvector dimension-mismatch on the first write.
        if ($dimensions < 1) {
            throw new \InvalidArgumentException(
                "FakeProvider requires a positive embedding dimension; got {$dimensions}. "
                . 'Check KB_EMBEDDINGS_DIMENSIONS / ai.providers.fake.dimensions.'
            );
        }

        // Constant unit vector — [1, 0, 0, …]. Every text (corpus chunk OR
        // query) maps to the same vector, so cosine similarity is always 1.0
        // and retrieval deterministically returns whatever was ingested. No
        // external call; no randomness.
        $vector = array_fill(0, $dimensions, 0.0);
        $vector[0] = 1.0;

        $embeddings = array_map(static fn () => $vector, $texts);

        return new EmbeddingsResponse(
            embeddings: $embeddings,
            provider: 'fake',
            model: $this->modelName('embeddings_model'),
            totalTokens: 0,
        );
    }

    public function name(): string
    {
        return 'fake';
    }

    /**
     * Single source of truth for the model string stamped on responses:
     * the injected config (ai.providers.fake.{chat_model,embeddings_model}),
     * falling back to the canonical default when constructed bare (e.g. a
     * unit test that `new FakeProvider()`s without config). Keeps the
     * chat-log model column + EmbeddingCacheService::resolveModelName() lookup
     * key aligned with what is actually persisted.
     */
    private function modelName(string $key): string
    {
        $model = $this->config[$key] ?? null;

        return is_string($model) && $model !== '' ? $model : 'fake-deterministic';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }
}
