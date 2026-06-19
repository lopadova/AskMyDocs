<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Concerns\FallbackStreaming;
use App\Ai\Providers\Concerns\SdkChat;

/**
 * Anthropic provider — adapts AskMyDocs's `AiProviderInterface` over the
 * official `laravel/ai` SDK (native `anthropic` driver).
 *
 * v8.16/W2 migrated this off raw `Http::` onto the SDK so the
 * `laravel-ai-finops` metering hook records every Anthropic chat turn via the
 * `AgentPrompted` lifecycle event (no more `AiCallMeter` bridge for this
 * provider). Anthropic exposes no tool path in AskMyDocs (`TOOL_CAPABLE_PROVIDERS`
 * = openai/openrouter only) and no embeddings API, so the whole surface is the
 * clean SDK chat path. Transport / retry / error-mapping now live in the SDK's
 * Anthropic gateway; this class only maps the SDK `AgentResponse` onto the
 * AskMyDocs `AiResponse` DTO. Config is read from `config('ai.providers.anthropic')`
 * in the SDK shape (driver / key / url / models.text.default) — see config/ai.php.
 */
final class AnthropicProvider implements AiProviderInterface
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

    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
    {
        // Anthropic supports SSE streaming natively, but we wire the fallback
        // for now — native token-by-token streaming is a W3 enhancement that
        // overrides this body without changing the public contract.
        return $this->streamFromChat($systemPrompt, $messages, $options);
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        throw new \RuntimeException(
            'Anthropic does not provide an embeddings API. '
            . 'Configure AI_EMBEDDINGS_PROVIDER (e.g. openai, gemini).'
        );
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function supportsEmbeddings(): bool
    {
        return false;
    }
}
