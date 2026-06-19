<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Concerns\FallbackStreaming;
use App\Ai\Providers\Concerns\SdkChat;

/**
 * Gemini provider — adapts AskMyDocs's `AiProviderInterface` over the official
 * `laravel/ai` SDK (native `gemini` driver).
 *
 * v8.16/W2 migrated this off raw `Http::` onto the SDK. The Gemini-specific
 * wire details — the `assistant`→`model` role remap, the `x-goog-api-key`
 * HEADER auth (R-logging-security: never a URL query string), the
 * `system_instruction` / `generationConfig.maxOutputTokens` shape, and the
 * `batchEmbedContents` (768-dim text-embedding-004) embeddings call — now live
 * in the SDK's Gemini gateway. This class only maps the SDK responses onto the
 * AskMyDocs DTOs. Gemini has no tool path in AskMyDocs, so the whole surface is
 * the clean SDK path, metered by the finops AgentPrompted / EmbeddingsGenerated
 * hooks (no `AiCallMeter` bridge). Config is read from
 * `config('ai.providers.gemini')` in the SDK shape — see config/ai.php.
 */
final class GeminiProvider implements AiProviderInterface
{
    use FallbackStreaming;
    use SdkChat;

    public function __construct(private readonly array $config) {}

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        return $this->chatViaSdk($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], $options);
    }

    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        return $this->chatViaSdk($systemPrompt, $messages, $options);
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        return $this->embeddingsViaSdk($texts);
    }

    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
    {
        // Gemini supports SSE streaming natively; the fallback is wired for now
        // and a W3 enhancement overrides this body without changing the contract.
        return $this->streamFromChat($systemPrompt, $messages, $options);
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }
}
