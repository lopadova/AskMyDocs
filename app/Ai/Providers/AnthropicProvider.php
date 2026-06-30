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
 * Anthropic provider — HYBRID adapter (no-tools chat via SDK, with-tools chat
 * via raw `Http::` `/messages`).
 *
 * v8.16/W2 migrated the no-tools chat path off raw `Http::` onto the
 * `laravel/ai` SDK (native `anthropic` driver) so the `laravel-ai-finops`
 * metering hook records every no-tools turn via the `AgentPrompted` lifecycle
 * event. The provider-extension step then added the MCP **with-tools** turn back
 * on raw `Http::` `/messages`: the SDK OWNS its own tool loop (auto-executes PHP
 * `Tool` classes) and has no raw-JSON-schema passthrough, so it cannot host
 * AskMyDocs's external-MCP tool loop (`McpToolCallingService` passes dynamic JSON
 * tools and replays the assistant `tool_use` + `role:'tool'` results itself).
 * That residual Http turn is metered by the {@see \App\FinOps\AiCallMeter} bridge,
 * which `AiManager` invokes ONLY for the with-tools path (double-count guard) —
 * anthropic is now in `AiManager::SDK_HYBRID_TOOL_PROVIDERS`.
 *
 * The Http branch translates the OpenAI-shaped tools + history that
 * `McpToolCallingService` produces into the Anthropic Messages API shape (tools
 * with `input_schema`, `tool_use` / `tool_result` content blocks, system prompt
 * as a top-level field) and translates the native `tool_use` response blocks back
 * into the normalized `{id, name, arguments}` tool-call shape the loop consumes.
 *
 * Anthropic exposes no embeddings API. Config is read from
 * `config('ai.providers.anthropic')` in the SDK shape (driver / key / url /
 * api_version / models.text.default); the Http branch reads the same keys.
 */
final class AnthropicProvider implements AiProviderInterface
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
        // Raw Http:: /messages branch for ANY tool turn — the explicit with-tools
        // call (`tools` in options) AND the MCP loop's final answer turn (no
        // `tools`, but the history carries assistant `tool_calls` / `role:'tool'`
        // messages the SDK can't represent). Everything else → SDK.
        if (array_key_exists('tools', $options) || ToolTurnDetector::historyHasToolTurn($messages)) {
            return $this->chatViaHttpWithTools($systemPrompt, $messages, $options);
        }

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

    /**
     * The MCP with-tools chat turn over raw `Http::` `/messages` (Anthropic
     * Messages API).
     *
     * Translates the OpenAI-shaped tools + history (`McpToolCallingService`'s
     * output) into the Anthropic wire shape — system as a top-level field, tools
     * with `input_schema`, assistant `tool_use` blocks, and user `tool_result`
     * blocks — then maps the native `tool_use` response blocks back to the
     * normalized `{id, name, arguments}` tool-call shape. Reads the SDK-shaped
     * config keys (key / url / api_version / models.text.default) so config has a
     * single source of truth.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<string, mixed>  $options
     */
    private function chatViaHttpWithTools(string $systemPrompt, array $messages, array $options): AiResponse
    {
        $defaultModel = $this->config['models']['text']['default'] ?? 'claude-sonnet-4-20250514';

        $payload = [
            'model' => $options['model'] ?? $defaultModel,
            'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
            'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.2,
            'system' => $systemPrompt,
            'messages' => $this->translateMessages($messages),
        ];

        // `tools` is absent on the MCP final answer turn (tool history, no tools);
        // only attach when the caller actually offers tools this turn. `tool_choice`
        // is meaningless without `tools`, so it is gated under the same check.
        if (array_key_exists('tools', $options)) {
            $payload['tools'] = $this->translateTools($options['tools']);
            $toolChoice = $this->translateToolChoice($options['tool_choice'] ?? null);
            if ($toolChoice !== null) {
                $payload['tool_choice'] = $toolChoice;
            }
        }

        $baseUrl = rtrim($this->config['url'] ?? 'https://api.anthropic.com/v1', '/');

        $response = Http::withHeaders([
            'x-api-key' => $this->config['key'],
            'anthropic-version' => $this->config['api_version'] ?? '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout($this->config['timeout'] ?? 120)
            ->post("{$baseUrl}/messages", $payload);

        $response->throw();
        $data = $response->json();

        return $this->toAiResponseFromHttp(is_array($data) ? $data : [], $defaultModel);
    }

    /**
     * Translate OpenAI-shaped function tools into Anthropic tool definitions.
     *
     * OpenAI: `{type:'function', function:{name, description, parameters}}`
     * Anthropic: `{name, description, input_schema}`.
     *
     * @param  mixed  $tools
     * @return list<array<string, mixed>>
     */
    private function translateTools(mixed $tools): array
    {
        if (! is_array($tools)) {
            return [];
        }

        $translated = [];
        foreach ($tools as $tool) {
            if (! is_array($tool)) {
                continue;
            }

            $name = (string) data_get($tool, 'function.name', $tool['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $schema = data_get($tool, 'function.parameters', $tool['parameters'] ?? []);
            if (! is_array($schema) || $schema === []) {
                $schema = ['type' => 'object', 'properties' => (object) []];
            }

            $translated[] = [
                'name' => $name,
                'description' => (string) data_get($tool, 'function.description', $tool['description'] ?? ''),
                'input_schema' => $schema,
            ];
        }

        return $translated;
    }

    /**
     * Translate OpenAI `tool_choice` into Anthropic's shape.
     *
     * 'auto' → {type:'auto'}; 'required'/'any' → {type:'any'}; null/absent → null.
     *
     * @return array<string, string>|null
     */
    private function translateToolChoice(mixed $toolChoice): ?array
    {
        if (! is_string($toolChoice) || $toolChoice === '') {
            return null;
        }

        return match ($toolChoice) {
            'auto' => ['type' => 'auto'],
            'required', 'any' => ['type' => 'any'],
            default => ['type' => 'auto'],
        };
    }

    /**
     * Translate the OpenAI-shaped history into Anthropic `messages` (system is a
     * top-level field, NOT a message). Assistant `tool_calls` become `tool_use`
     * content blocks; a `role:'tool'` result becomes a user message carrying a
     * `tool_result` block. One user message per tool result preserves ordering.
     *
     * @param  array<int, mixed>  $messages
     * @return list<array<string, mixed>>
     */
    private function translateMessages(array $messages): array
    {
        $translated = [];
        foreach ($messages as $msg) {
            if (! is_array($msg)) {
                continue;
            }

            $role = (string) ($msg['role'] ?? '');
            if ($role === '') {
                continue;
            }

            if ($role === 'tool') {
                $translated[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => (string) ($msg['tool_call_id'] ?? ''),
                        'content' => is_string($msg['content'] ?? null) ? (string) $msg['content'] : '',
                    ]],
                ];
                continue;
            }

            if ($role === 'assistant') {
                $translated[] = [
                    'role' => 'assistant',
                    'content' => $this->translateAssistantContent($msg),
                ];
                continue;
            }

            // Default: user (and any other role) → a text block.
            $text = is_string($msg['content'] ?? null) ? (string) $msg['content'] : '';
            if ($text === '') {
                continue;
            }

            $translated[] = [
                'role' => $role,
                'content' => [['type' => 'text', 'text' => $text]],
            ];
        }

        return $translated;
    }

    /**
     * Build an assistant message's `content` array: an optional leading text
     * block (only when non-empty) followed by one `tool_use` block per
     * OpenAI-shaped `tool_calls` entry.
     *
     * @param  array<string, mixed>  $msg
     * @return list<array<string, mixed>>
     */
    private function translateAssistantContent(array $msg): array
    {
        $content = [];

        $text = is_string($msg['content'] ?? null) ? (string) $msg['content'] : '';
        if ($text !== '') {
            $content[] = ['type' => 'text', 'text' => $text];
        }

        $toolCalls = $msg['tool_calls'] ?? null;
        if (is_array($toolCalls)) {
            foreach ($toolCalls as $toolCall) {
                if (! is_array($toolCall)) {
                    continue;
                }

                $name = (string) data_get($toolCall, 'function.name', $toolCall['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $content[] = [
                    'type' => 'tool_use',
                    'id' => (string) data_get($toolCall, 'id', 'tool_' . bin2hex(random_bytes(8))),
                    'name' => $name,
                    'input' => $this->decodeArgumentsToObject(
                        data_get($toolCall, 'function.arguments', $toolCall['arguments'] ?? '')
                    ),
                ];
            }
        }

        return $content;
    }

    /**
     * Map an Anthropic Messages response onto AiResponse: concatenate text
     * blocks for the content, and turn each `tool_use` block into the normalized
     * `{id, name, arguments(JSON string)}` tool-call shape.
     *
     * @param  array<string, mixed>  $data
     */
    private function toAiResponseFromHttp(array $data, string $defaultModel): AiResponse
    {
        $blocks = is_array($data['content'] ?? null) ? $data['content'] : [];

        $text = '';
        $toolCalls = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            if ($type === 'text') {
                $text .= is_string($block['text'] ?? null) ? (string) $block['text'] : '';
                continue;
            }

            if ($type === 'tool_use') {
                $name = (string) ($block['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ('tool_' . bin2hex(random_bytes(8)))),
                    'name' => $name,
                    'arguments' => $this->encodeArguments($block['input'] ?? []),
                ];
            }
        }

        $promptTokens = $data['usage']['input_tokens'] ?? null;
        $completionTokens = $data['usage']['output_tokens'] ?? null;
        $totalTokens = ($promptTokens !== null || $completionTokens !== null)
            ? (int) $promptTokens + (int) $completionTokens
            : null;

        return new AiResponse(
            content: $text,
            provider: $this->name(),
            model: is_string($data['model'] ?? null) ? (string) $data['model'] : $defaultModel,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            finishReason: is_string($data['stop_reason'] ?? null) ? (string) $data['stop_reason'] : null,
            toolCalls: $toolCalls,
        );
    }

    /**
     * Decode a tool-call `arguments` JSON string into an associative array for
     * the Anthropic `tool_use.input` field. Anthropic requires `input` to be an
     * object, so a non-decodable / non-array value falls back to `{}`.
     *
     * @return array<string, mixed>
     */
    private function decodeArgumentsToObject(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (! is_string($arguments) || $arguments === '') {
            return [];
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodeArguments(mixed $input): string
    {
        if (is_string($input)) {
            return $input;
        }

        $json = json_encode($input === [] ? (object) [] : $input, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '{}';
        }

        return $json;
    }
}
