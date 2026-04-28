<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;

/**
 * Regolo provider — adapts AskMyDocs's `AiProviderInterface` over the
 * official `laravel/ai` SDK and the `padosoft/laravel-ai-regolo`
 * extension package.
 *
 * The transport, retry, error-mapping, message-shape, tool-loop and
 * streaming logic now live in `padosoft/laravel-ai-regolo` (47 unit
 * tests, 6-cell PHP × Laravel matrix). This class only translates
 * between the AskMyDocs DTO surface (`AiResponse` / `EmbeddingsResponse`)
 * and the SDK DTO surface (`Laravel\Ai\Responses\AgentResponse` /
 * `EmbeddingsResponse`) so existing callers don't change.
 *
 * Configuration is read from `config('ai.providers.regolo')` in the
 * SDK shape — see `config/ai.php` for the canonical entry.
 */
final class RegoloProvider implements AiProviderInterface
{
    public function __construct(private readonly array $config) {}

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        $agent = new AnonymousAgent(
            instructions: $systemPrompt,
            messages: [],
            tools: [],
        );

        $sdkResponse = $agent->prompt(
            $userMessage,
            [],
            'regolo',
            $this->resolveTextModel($options),
            $this->config['timeout'] ?? null,
        );

        return $this->toAiResponse($sdkResponse);
    }

    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        if (empty($messages)) {
            throw new \InvalidArgumentException('chatWithHistory requires at least one message.');
        }

        $last = end($messages);
        $history = array_slice($messages, 0, -1);

        $agent = new AnonymousAgent(
            instructions: $systemPrompt,
            messages: $this->mapHistoryToSdkMessages($history),
            tools: [],
        );

        $sdkResponse = $agent->prompt(
            $last['content'],
            [],
            'regolo',
            $this->resolveTextModel($options),
            $this->config['timeout'] ?? null,
        );

        return $this->toAiResponse($sdkResponse);
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        $sdkResponse = Embeddings::for($texts)->generate(
            'regolo',
            $this->config['models']['embeddings']['default'] ?? null,
        );

        return new EmbeddingsResponse(
            embeddings: $sdkResponse->embeddings,
            provider: $this->name(),
            model: $sdkResponse->meta->model,
            totalTokens: $sdkResponse->tokens,
        );
    }

    public function name(): string
    {
        return 'regolo';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, UserMessage|AssistantMessage>
     */
    private function mapHistoryToSdkMessages(array $history): array
    {
        return array_map(
            fn (array $msg) => match ($msg['role']) {
                'user' => new UserMessage($msg['content']),
                'assistant' => new AssistantMessage($msg['content']),
                default => throw new \InvalidArgumentException("Unsupported message role [{$msg['role']}]."),
            },
            $history,
        );
    }

    private function resolveTextModel(array $options): ?string
    {
        return $options['model']
            ?? $this->config['models']['text']['default']
            ?? null;
    }

    private function toAiResponse(\Laravel\Ai\Responses\AgentResponse $sdkResponse): AiResponse
    {
        $promptTokens = $sdkResponse->usage->promptTokens;
        $completionTokens = $sdkResponse->usage->completionTokens;

        return new AiResponse(
            content: $sdkResponse->text,
            provider: $this->name(),
            model: $sdkResponse->meta->model,
            promptTokens: $promptTokens > 0 ? $promptTokens : null,
            completionTokens: $completionTokens > 0 ? $completionTokens : null,
            totalTokens: ($promptTokens + $completionTokens) > 0 ? $promptTokens + $completionTokens : null,
            finishReason: $sdkResponse->steps->first()?->finishReason?->value,
        );
    }
}
