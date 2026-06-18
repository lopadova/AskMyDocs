<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Concerns\FallbackStreaming;
use App\Ai\Providers\Concerns\SdkChat;
use App\Ai\Support\ToolTurnDetector;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI provider — HYBRID adapter (v8.16/W2).
 *
 * The no-tools chat turn and embeddings flow through the official `laravel/ai`
 * SDK (native `openai` driver — `/responses` + `/embeddings`), so the
 * `laravel-ai-finops` metering hook records them via the SDK lifecycle events
 * (`AgentPrompted` / `EmbeddingsGenerated`) — no `AiCallMeter` bridge for those.
 *
 * The MCP **with-tools** turn stays on the existing raw `Http::`
 * `/chat/completions` branch: the SDK OWNS its tool loop (auto-executes PHP
 * `Tool` classes via the `/responses` continuation) and has no raw-JSON-schema
 * passthrough, so it cannot host AskMyDocs's external-MCP tool loop
 * (`McpToolCallingService` passes dynamic JSON tools and replays
 * `role:'tool'` + `tool_call_id` itself). See
 * docs/v4-platform/W2-sdk-migration-findings.md (tool-calling verdict = HYBRID).
 * That residual Http turn is metered by the {@see \App\FinOps\AiCallMeter}
 * bridge, which `AiManager` invokes ONLY for the with-tools path (double-count
 * guard).
 *
 * Config is read from `config('ai.providers.openai')` in the SDK shape
 * (driver / key / url / models); the Http branch reads the same keys.
 */
final class OpenAiProvider implements AiProviderInterface
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
        // Raw Http:: /chat/completions branch for ANY tool turn — the explicit
        // with-tools call (`tools` in options) AND the MCP loop's final answer
        // turn (no `tools`, but the history carries assistant `tool_calls` /
        // `role:'tool'` messages the SDK can't represent). Everything else → SDK.
        if (array_key_exists('tools', $options) || ToolTurnDetector::historyHasToolTurn($messages)) {
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
        // OpenAI supports `stream: true` over SSE; the fallback is wired for now
        // and a W3 enhancement overrides this body without changing the contract.
        return $this->streamFromChat($systemPrompt, $messages, $options);
    }

    public function name(): string
    {
        return 'openai';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }

    /**
     * The MCP with-tools chat turn over raw `Http::` `/chat/completions`.
     *
     * Preserves the dynamic-JSON-tools passthrough + `tool_choice` + the
     * assistant/`tool` replay (`tool_calls` / `tool_call_id` / `name`) that the
     * SDK cannot express. Reads the SDK-shaped config keys (key / url /
     * models.text.default) so config has a single source of truth.
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

        $payload = [
            'model' => $options['model'] ?? $this->config['models']['text']['default'] ?? 'gpt-4o',
            'messages' => $apiMessages,
            'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.2,
            'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
        ];

        // `tools` is absent on the MCP final answer turn (tool history, no tools);
        // only attach when the caller actually offers tools this turn. `tool_choice`
        // is meaningless without `tools` and would make OpenAI 400, so it is gated
        // under the same check.
        if (array_key_exists('tools', $options)) {
            $payload['tools'] = $options['tools'];
            if (array_key_exists('tool_choice', $options)) {
                $payload['tool_choice'] = $options['tool_choice'];
            }
        }

        $baseUrl = rtrim($this->config['url'] ?? 'https://api.openai.com/v1', '/');

        $response = Http::withToken($this->config['key'])
            ->timeout($this->config['timeout'] ?? 120)
            ->post("{$baseUrl}/chat/completions", $payload);

        $response->throw();
        $data = $response->json();
        $message = is_array($data['choices'][0]['message'] ?? null) ? $data['choices'][0]['message'] : [];

        return new AiResponse(
            content: is_string($message['content'] ?? null) ? $message['content'] : '',
            provider: $this->name(),
            model: $data['model'],
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
