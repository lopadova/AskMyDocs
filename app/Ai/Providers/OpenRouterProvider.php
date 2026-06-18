<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Concerns\FallbackStreaming;
use App\Ai\Providers\Concerns\SdkChat;
use Illuminate\Support\Facades\Http;

/**
 * OpenRouter provider — HYBRID adapter (v8.16/W2).
 *
 * Like OpenAI, the no-tools chat turn + embeddings flow through the official
 * `laravel/ai` SDK (native `openrouter` driver — both use the OpenAI-compatible
 * `/chat/completions` + `/embeddings` endpoints), so the `laravel-ai-finops`
 * metering hook records them via the SDK lifecycle events. The MCP **with-tools**
 * turn stays on the raw `Http::` `/chat/completions` branch (the SDK cannot host
 * AskMyDocs's external-MCP tool loop — see W2-sdk-migration-findings.md, verdict
 * = HYBRID). That residual Http turn is metered by the {@see \App\FinOps\AiCallMeter}
 * bridge, which `AiManager` invokes ONLY for the with-tools path.
 *
 * OpenRouter cost capture: the SDK call sets `usage: { include: true }` (via
 * {@see sdkProviderOptions()}) so OpenRouter returns the real billed `usage.cost`
 * the finops actual-cost capture reads. Attribution headers come from the same
 * `http_referer` / `x_title` config on both branches, but the header NAME differs:
 * the SDK gateway sends `HTTP-Referer` + `X-OpenRouter-Title`, while the raw Http
 * with-tools branch sends `HTTP-Referer` + the legacy `X-Title` (both are valid
 * OpenRouter attribution headers).
 *
 * Config is read from `config('ai.providers.openrouter')` in the SDK shape
 * (driver / key / url / http_referer / x_title / models); the Http branch reads
 * the same keys.
 */
final class OpenRouterProvider implements AiProviderInterface
{
    use FallbackStreaming;
    use SdkChat;

    public function __construct(private readonly array $config) {}

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        return $this->chatWithHistory($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], $options);
    }

    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        // With-tools turn → keep the raw Http:: /chat/completions branch (the SDK
        // cannot host AskMyDocs's external MCP tool loop). No-tools turn → SDK.
        if (array_key_exists('tools', $options)) {
            return $this->chatViaHttpWithTools($systemPrompt, $messages, $options);
        }

        return $this->chatViaSdk($systemPrompt, $messages, $options);
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        return $this->embeddingsViaSdk($texts);
    }

    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
    {
        // OpenRouter relays `stream: true` over SSE for upstream models that
        // support it; the fallback is wired for now and a W3 enhancement
        // overrides this body without changing the public contract.
        return $this->streamFromChat($systemPrompt, $messages, $options);
    }

    public function name(): string
    {
        return 'openrouter';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }

    /**
     * Ask OpenRouter to return the real billed cost on the SDK path so the
     * finops actual-cost capture can read `usage.cost`. Harmless when actual-cost
     * is off (it only adds an accounting field to the response).
     *
     * @return array<string, mixed>
     */
    protected function sdkProviderOptions(): array
    {
        return ['usage' => ['include' => true]];
    }

    /**
     * The MCP with-tools chat turn over raw `Http::` `/chat/completions`.
     *
     * Preserves the dynamic-JSON-tools passthrough + `tool_choice` + the
     * assistant/`tool` replay that the SDK cannot express, plus the OpenRouter
     * attribution headers. Reads the SDK-shaped config keys (key / url /
     * http_referer / x_title / models.text.default) — single source of truth.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<string, mixed>  $options
     */
    private function chatViaHttpWithTools(string $systemPrompt, array $messages, array $options): AiResponse
    {
        $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($messages as $msg) {
            if (! is_array($msg)) {
                continue;
            }

            $role = (string) ($msg['role'] ?? '');
            if ($role === '') {
                continue;
            }

            $apiMessage = [
                'role' => $role,
                'content' => is_string($msg['content'] ?? null) ? (string) $msg['content'] : '',
            ];

            if (array_key_exists('tool_calls', $msg)) {
                $apiMessage['tool_calls'] = $msg['tool_calls'];
            }
            if (array_key_exists('tool_call_id', $msg)) {
                $apiMessage['tool_call_id'] = (string) $msg['tool_call_id'];
            }
            if (array_key_exists('name', $msg)) {
                $apiMessage['name'] = (string) $msg['name'];
            }

            $apiMessages[] = $apiMessage;
        }

        $defaultModel = $this->config['models']['text']['default'] ?? 'anthropic/claude-sonnet-4-20250514';

        $payload = [
            'model' => $options['model'] ?? $defaultModel,
            'messages' => $apiMessages,
            'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.2,
            'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
        ];

        $payload['tools'] = $options['tools'];
        if (array_key_exists('tool_choice', $options)) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        $baseUrl = rtrim($this->config['url'] ?? 'https://openrouter.ai/api/v1', '/');

        $response = Http::withToken($this->config['key'])
            ->withHeaders([
                'HTTP-Referer' => $this->config['http_referer'] ?? config('app.url', ''),
                'X-Title' => $this->config['x_title'] ?? config('app.name', 'Enterprise KB'),
            ])
            ->timeout($this->config['timeout'] ?? 120)
            ->post("{$baseUrl}/chat/completions", $payload);

        $response->throw();
        $data = $response->json();
        $message = is_array($data['choices'][0]['message'] ?? null) ? $data['choices'][0]['message'] : [];

        return new AiResponse(
            content: is_string($message['content'] ?? null) ? $message['content'] : '',
            provider: $this->name(),
            model: $data['model'] ?? $defaultModel,
            promptTokens: $data['usage']['prompt_tokens'] ?? null,
            completionTokens: $data['usage']['completion_tokens'] ?? null,
            totalTokens: $data['usage']['total_tokens'] ?? null,
            finishReason: $data['choices'][0]['finish_reason'] ?? null,
            toolCalls: $this->normalizeToolCalls($message['tool_calls'] ?? null),
        );
    }

    private function normalizeToolCalls(mixed $rawToolCalls): array
    {
        if (! is_array($rawToolCalls)) {
            return [];
        }

        $toolCalls = [];
        foreach ($rawToolCalls as $toolCall) {
            if (! is_array($toolCall)) {
                continue;
            }

            $name = (string) data_get($toolCall, 'function.name', $toolCall['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $toolCalls[] = [
                'id' => (string) data_get($toolCall, 'id', 'tool_' . bin2hex(random_bytes(8))),
                'name' => $name,
                'arguments' => $this->toolArgumentsToString(
                    data_get($toolCall, 'function.arguments', $toolCall['arguments'] ?? '')
                ),
            ];
        }

        return $toolCalls;
    }

    private function toolArgumentsToString(mixed $arguments): string
    {
        if (is_string($arguments)) {
            return $arguments;
        }

        $json = json_encode($arguments, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '{}';
        }

        return $json;
    }
}
