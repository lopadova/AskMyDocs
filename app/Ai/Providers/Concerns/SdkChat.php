<?php

namespace App\Ai\Providers\Concerns;

use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Internal\SdkAnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Shared adapter logic for the native-driver `laravel/ai` SDK providers
 * (OpenAI / Anthropic / Gemini / OpenRouter) migrated off raw `Http::` in
 * v8.16/W2. Mirrors the proven shape of `App\Ai\Providers\RegoloProvider`
 * (the SDK template) so every provider maps the SDK `AgentResponse` onto the
 * AskMyDocs `AiResponse` DTO identically and propagates per-call
 * `max_tokens` / `temperature` through `SdkAnonymousAgent`.
 *
 * The using class MUST expose `private readonly array $config` (the SDK-shaped
 * `config('ai.providers.<name>')` block) and implement `name(): string`.
 *
 * This trait covers ONLY the no-tools chat turn. Providers that also serve the
 * MCP with-tools turn (openai / openrouter) keep that branch on raw `Http::`
 * in their own class — the SDK cannot host AskMyDocs's external tool loop
 * (see docs/v4-platform/W2-sdk-migration-findings.md, tool-calling verdict).
 */
trait SdkChat
{
    /**
     * Run a single no-tools chat turn through the laravel/ai SDK.
     *
     * @param  array<int, array{role: string, content: string}>  $messages  Full
     *         history; must be non-empty, end with role `user`, every entry a
     *         non-empty string `content`, roles limited to user/assistant
     *         (system arrives via $systemPrompt).
     * @param  array<string, mixed>  $options
     *
     * @throws \InvalidArgumentException When a precondition is violated — catch
     *         at the call site to tell caller bugs from network/provider errors.
     */
    protected function chatViaSdk(string $systemPrompt, array $messages, array $options): AiResponse
    {
        if (empty($messages)) {
            throw new \InvalidArgumentException('chatWithHistory requires at least one message.');
        }

        $last = end($messages);
        if (! is_array($last) || ($last['role'] ?? null) !== 'user') {
            throw new \InvalidArgumentException(sprintf(
                'chatWithHistory requires the last message to have role="user"; got role="%s".',
                is_array($last) ? ($last['role'] ?? '(missing)') : '(non-array)'
            ));
        }
        if (! is_string($last['content'] ?? null) || $last['content'] === '') {
            throw new \InvalidArgumentException(
                'chatWithHistory requires the last message to have a non-empty string "content".'
            );
        }

        $history = array_slice($messages, 0, -1);

        $agent = $this->makeSdkAgent($systemPrompt, $this->mapHistoryToSdkMessages($history), $options);

        $sdkResponse = $agent->prompt(
            $last['content'],
            [],
            $this->name(),
            $this->resolveTextModel($options),
            $this->config['timeout'] ?? null,
        );

        return $this->toAiResponse($sdkResponse);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, UserMessage|AssistantMessage>
     */
    protected function mapHistoryToSdkMessages(array $history): array
    {
        return array_map(
            function (mixed $msg): UserMessage|AssistantMessage {
                if (! is_array($msg)) {
                    throw new \InvalidArgumentException(
                        'History entries must be associative arrays with role+content keys.'
                    );
                }
                $role = $msg['role'] ?? null;
                $content = $msg['content'] ?? null;
                if (! is_string($content) || $content === '') {
                    throw new \InvalidArgumentException(
                        'History entry requires a non-empty string "content".'
                    );
                }

                return match ($role) {
                    'user' => new UserMessage($content),
                    'assistant' => new AssistantMessage($content),
                    default => throw new \InvalidArgumentException(sprintf(
                        'Unsupported message role [%s].',
                        is_string($role) ? $role : '(missing)'
                    )),
                };
            },
            $history,
        );
    }

    protected function resolveTextModel(array $options): ?string
    {
        return $options['model']
            ?? $this->config['models']['text']['default']
            ?? null;
    }

    /**
     * @param  iterable<int, UserMessage|AssistantMessage>  $messages
     * @param  array<string, mixed>  $options
     */
    protected function makeSdkAgent(string $systemPrompt, iterable $messages, array $options): SdkAnonymousAgent
    {
        return new SdkAnonymousAgent(
            instructions: $systemPrompt,
            messages: $messages,
            tools: [],
            maxTokens: $this->resolveMaxTokens($options),
            temperature: $this->resolveTemperature($options),
            providerOptions: $this->sdkProviderOptions(),
        );
    }

    /**
     * Provider-specific request-body options merged into the SDK call.
     *
     * Default none; OpenRouter overrides this to set `usage: { include: true }`
     * so the response carries the real billed `usage.cost` (v8.16/W2).
     *
     * @return array<string, mixed>
     */
    protected function sdkProviderOptions(): array
    {
        return [];
    }

    protected function resolveMaxTokens(array $options): ?int
    {
        $value = $options['max_tokens'] ?? $this->config['max_tokens'] ?? null;
        if ($value === null) {
            return null;
        }
        // `(int) 'abc'` quietly yields 0 → `max_tokens=0` on the wire. Reject loudly.
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf(
                'max_tokens must be numeric (int or numeric string); got %s.',
                get_debug_type($value)
            ));
        }

        return (int) $value;
    }

    protected function resolveTemperature(array $options): ?float
    {
        $value = $options['temperature'] ?? $this->config['temperature'] ?? null;
        if ($value === null) {
            return null;
        }
        // `(float) 'hot'` silently becomes 0.0 (greedy decode). Reject loudly.
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf(
                'temperature must be numeric (float, int, or numeric string); got %s.',
                get_debug_type($value)
            ));
        }

        return (float) $value;
    }

    /**
     * Generate embeddings through the laravel/ai SDK (native driver).
     *
     * @param  list<string>  $texts
     */
    protected function embeddingsViaSdk(array $texts): EmbeddingsResponse
    {
        $sdkResponse = Embeddings::for($texts)->generate(
            $this->name(),
            $this->config['models']['embeddings']['default'] ?? null,
        );

        return new EmbeddingsResponse(
            embeddings: $sdkResponse->embeddings,
            provider: $this->name(),
            model: $sdkResponse->meta->model,
            totalTokens: $sdkResponse->tokens,
        );
    }

    protected function toAiResponse(AgentResponse $sdkResponse): AiResponse
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
            // Only the LAST step carries the finish reason for the final text the
            // caller receives via `$sdkResponse->text`; mid-loop tool steps would
            // mislead chat-log analytics + few-shot routing. See RegoloProvider.
            finishReason: $sdkResponse->steps->last()?->finishReason?->value,
        );
    }
}
