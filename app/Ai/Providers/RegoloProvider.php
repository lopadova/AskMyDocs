<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Internal\RegoloAnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;

/**
 * Regolo provider — adapts AskMyDocs's `AiProviderInterface` over the
 * official `laravel/ai` SDK and the `padosoft/laravel-ai-regolo`
 * extension package.
 *
 * The transport, retry, error-mapping, message-shape, tool-loop and
 * streaming logic now live in `padosoft/laravel-ai-regolo` — the
 * extension package owns the SDK-side test surface (matrix CI runs
 * on every PHP × Laravel cell it supports; see the package's own
 * README for the current count). This class only translates between
 * the AskMyDocs DTO surface (`AiResponse` / `EmbeddingsResponse`) and
 * the SDK DTO surface (`Laravel\Ai\Responses\AgentResponse` /
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
        $agent = $this->makeAgent($systemPrompt, [], $options);

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
        if (($last['role'] ?? null) !== 'user') {
            // The SDK's `Promptable::prompt(string $prompt, ...)` shape
            // expects the *new* user turn as a string argument, with all
            // earlier turns supplied via the agent's `messages` iterable.
            // If the caller hands us a history that ends in an assistant /
            // system / tool turn, treating `$last['content']` as the
            // prompt would silently impersonate the assistant — at best a
            // confusing model response, at worst a prompt-injection
            // surface. Surface the misuse loudly.
            throw new \InvalidArgumentException(sprintf(
                'chatWithHistory requires the last message to have role="user"; got role="%s".',
                $last['role'] ?? '(missing)'
            ));
        }

        $history = array_slice($messages, 0, -1);

        $agent = $this->makeAgent(
            $systemPrompt,
            $this->mapHistoryToSdkMessages($history),
            $options,
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

    /**
     * Build the per-call agent.
     *
     * The laravel/ai SDK's `TextGenerationOptions::forAgent()` reads
     * `maxTokens()` and `temperature()` methods from the agent instance
     * (or PHP attributes on the class) when building the gateway
     * request — see `vendor/laravel/ai/src/Gateway/TextGenerationOptions.php`.
     * Plain `AnonymousAgent` exposes neither, which silently dropped
     * caller-supplied `$options['max_tokens']` (and provider-level
     * `temperature`) on the floor and broke `ConversationController::generateTitle`.
     *
     * `RegoloAnonymousAgent` adds the two methods so per-call options
     * reach `BuildsTextRequests::buildTextRequest()` in
     * `padosoft/laravel-ai-regolo`, which forwards them as
     * `body['max_tokens']` / `body['temperature']` on the wire.
     *
     * @param  iterable<int, UserMessage|AssistantMessage>  $messages
     * @param  array<string, mixed>  $options
     */
    private function makeAgent(string $systemPrompt, iterable $messages, array $options): RegoloAnonymousAgent
    {
        return new RegoloAnonymousAgent(
            instructions: $systemPrompt,
            messages: $messages,
            tools: [],
            maxTokens: $this->resolveMaxTokens($options),
            temperature: $this->resolveTemperature($options),
        );
    }

    private function resolveMaxTokens(array $options): ?int
    {
        $value = $options['max_tokens'] ?? $this->config['max_tokens'] ?? null;

        return is_null($value) ? null : (int) $value;
    }

    private function resolveTemperature(array $options): ?float
    {
        $value = $options['temperature'] ?? $this->config['temperature'] ?? null;

        return is_null($value) ? null : (float) $value;
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
