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
 * Gemini provider — HYBRID adapter (no-tools chat + embeddings via SDK,
 * with-tools chat via raw `Http::` `generateContent`).
 *
 * v8.16/W2 migrated the no-tools chat + embeddings path off raw `Http::` onto
 * the `laravel/ai` SDK (native `gemini` driver). The provider-extension step
 * then added the MCP **with-tools** turn back on raw `Http::`
 * `…/models/{model}:generateContent`: the SDK owns its own tool loop and has no
 * raw-JSON-schema passthrough, so it cannot host AskMyDocs's external-MCP tool
 * loop (`McpToolCallingService` passes dynamic JSON tools and replays the
 * assistant `functionCall` + `functionResponse` itself). That residual Http turn
 * is metered by the {@see \App\FinOps\AiCallMeter} bridge, which `AiManager`
 * invokes ONLY for the with-tools path — gemini is now in
 * `AiManager::SDK_HYBRID_TOOL_PROVIDERS`.
 *
 * The Http branch translates the OpenAI-shaped tools + history into the Gemini
 * `generateContent` shape (`functionDeclarations`, `functionCall` /
 * `functionResponse` parts, system prompt via `system_instruction`, no 'system'
 * role) and synthesizes a tool-call id (Gemini returns none — replay matches by
 * function NAME). The `x-goog-api-key` HEADER auth invariant (R-logging-security:
 * never a URL query string) is preserved on this branch too.
 *
 * Config is read from `config('ai.providers.gemini')` in the SDK shape; the Http
 * branch reads the same keys.
 */
final class GeminiProvider implements AiProviderInterface
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
        // Raw Http:: generateContent branch for ANY tool turn — the explicit
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

    /**
     * The MCP with-tools chat turn over raw `Http::`
     * `…/models/{model}:generateContent`.
     *
     * Translates the OpenAI-shaped tools + history into the Gemini wire shape —
     * `system_instruction` for the system prompt, `functionDeclarations` for the
     * tools, `functionCall` / `functionResponse` parts for the assistant/tool
     * replay, and the `user` / `model` role pair (Gemini has no 'system' role) —
     * then maps each native `functionCall` part back to the normalized
     * `{id, name, arguments}` tool-call shape (the id is synthesized; replay
     * matches by NAME). Authenticates via the `x-goog-api-key` HEADER, never the
     * URL query string (R-logging-security / H6). Reads the SDK-shaped config keys
     * so config has a single source of truth.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<string, mixed>  $options
     */
    private function chatViaHttpWithTools(string $systemPrompt, array $messages, array $options): AiResponse
    {
        $model = (string) ($options['model'] ?? $this->config['models']['text']['default'] ?? 'gemini-2.0-flash');

        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $this->translateContents($messages),
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.2,
                'maxOutputTokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
            ],
        ];

        // `tools` is absent on the MCP final answer turn (tool history, no tools);
        // only attach when the caller actually offers tools this turn. `tool_config`
        // is meaningless without `tools`, so it is gated under the same check.
        if (array_key_exists('tools', $options)) {
            $payload['tools'] = [['functionDeclarations' => $this->translateTools($options['tools'])]];
            $mode = $this->translateToolChoice($options['tool_choice'] ?? null);
            if ($mode !== null) {
                $payload['tool_config'] = ['function_calling_config' => ['mode' => $mode]];
            }
        }

        $baseUrl = rtrim($this->config['url'] ?? 'https://generativelanguage.googleapis.com/v1beta', '/');

        $response = Http::withHeaders([
            // R-logging-security / H6: the key goes in the header, NEVER the URL
            // query string (query strings leak into access / proxy logs + traces).
            'x-goog-api-key' => $this->config['key'],
            'content-type' => 'application/json',
        ])
            ->timeout($this->config['timeout'] ?? 120)
            ->post("{$baseUrl}/models/{$model}:generateContent", $payload);

        $response->throw();
        $data = $response->json();

        return $this->toAiResponseFromHttp(is_array($data) ? $data : [], $model);
    }

    /**
     * Translate OpenAI-shaped function tools into Gemini `functionDeclarations`.
     *
     * OpenAI: `{type:'function', function:{name, description, parameters}}`
     * Gemini: `{name, description, parameters}` (JSON schema). An empty schema
     * still sends `{type:'object'}` so the declaration stays valid.
     *
     * @param  mixed  $tools
     * @return list<array<string, mixed>>
     */
    private function translateTools(mixed $tools): array
    {
        if (! is_array($tools)) {
            return [];
        }

        $declarations = [];
        foreach ($tools as $tool) {
            if (! is_array($tool)) {
                continue;
            }

            $name = (string) data_get($tool, 'function.name', $tool['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $parameters = data_get($tool, 'function.parameters', $tool['parameters'] ?? []);
            $hasProperties = is_array($parameters)
                && is_array($parameters['properties'] ?? null)
                && $parameters['properties'] !== [];
            if (! $hasProperties) {
                $parameters = ['type' => 'object'];
            }

            $declarations[] = [
                'name' => $name,
                'description' => (string) data_get($tool, 'function.description', $tool['description'] ?? ''),
                'parameters' => $parameters,
            ];
        }

        return $declarations;
    }

    /**
     * Translate OpenAI `tool_choice` into the Gemini function-calling mode.
     *
     * 'auto' → 'AUTO'; 'required'/'any' → 'ANY'; null/absent → null (omit).
     */
    private function translateToolChoice(mixed $toolChoice): ?string
    {
        if (! is_string($toolChoice) || $toolChoice === '') {
            return null;
        }

        return match ($toolChoice) {
            'auto' => 'AUTO',
            'required', 'any' => 'ANY',
            default => 'AUTO',
        };
    }

    /**
     * Translate the OpenAI-shaped history into Gemini `contents`. System goes to
     * `system_instruction` (not here); `assistant`→`model`; assistant
     * `tool_calls` become `functionCall` parts; a `role:'tool'` result becomes a
     * user `functionResponse` part keyed by the tool NAME (Gemini matches
     * responses to calls by name, not id).
     *
     * @param  array<int, mixed>  $messages
     * @return list<array<string, mixed>>
     */
    private function translateContents(array $messages): array
    {
        $contents = [];
        foreach ($messages as $msg) {
            if (! is_array($msg)) {
                continue;
            }

            $role = (string) ($msg['role'] ?? '');
            if ($role === '') {
                continue;
            }

            if ($role === 'tool') {
                $contents[] = [
                    'role' => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => (string) ($msg['name'] ?? ''),
                            'response' => $this->toolResponsePayload($msg['content'] ?? null),
                        ],
                    ]],
                ];
                continue;
            }

            if ($role === 'assistant') {
                $contents[] = [
                    'role' => 'model',
                    'parts' => $this->translateModelParts($msg),
                ];
                continue;
            }

            $text = is_string($msg['content'] ?? null) ? (string) $msg['content'] : '';
            if ($text === '') {
                continue;
            }

            $contents[] = ['role' => 'user', 'parts' => [['text' => $text]]];
        }

        return $contents;
    }

    /**
     * Build a model message's `parts`: an optional leading text part (only when
     * non-empty) followed by one `functionCall` part per OpenAI-shaped
     * `tool_calls` entry.
     *
     * @param  array<string, mixed>  $msg
     * @return list<array<string, mixed>>
     */
    private function translateModelParts(array $msg): array
    {
        $parts = [];

        $text = is_string($msg['content'] ?? null) ? (string) $msg['content'] : '';
        if ($text !== '') {
            $parts[] = ['text' => $text];
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

                $parts[] = [
                    'functionCall' => [
                        'name' => $name,
                        'args' => $this->decodeArgumentsToObject(
                            data_get($toolCall, 'function.arguments', $toolCall['arguments'] ?? '')
                        ),
                    ],
                ];
            }
        }

        return $parts;
    }

    /**
     * Map a Gemini `generateContent` response onto AiResponse: concatenate text
     * parts for the content, and turn each `functionCall` part into the
     * normalized `{id, name, arguments(JSON string)}` shape (id synthesized —
     * Gemini returns none).
     *
     * @param  array<string, mixed>  $data
     */
    private function toAiResponseFromHttp(array $data, string $defaultModel): AiResponse
    {
        $parts = data_get($data, 'candidates.0.content.parts');
        $parts = is_array($parts) ? $parts : [];

        $text = '';
        $toolCalls = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (is_string($part['text'] ?? null)) {
                $text .= (string) $part['text'];
                continue;
            }

            $functionCall = $part['functionCall'] ?? null;
            if (is_array($functionCall)) {
                $name = (string) ($functionCall['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $toolCalls[] = [
                    'id' => 'call_' . bin2hex(random_bytes(8)),
                    'name' => $name,
                    'arguments' => $this->encodeArguments($functionCall['args'] ?? []),
                ];
            }
        }

        $promptTokens = data_get($data, 'usageMetadata.promptTokenCount');
        $completionTokens = data_get($data, 'usageMetadata.candidatesTokenCount');
        $totalTokens = data_get($data, 'usageMetadata.totalTokenCount');

        $finishReason = data_get($data, 'candidates.0.finishReason');
        $modelVersion = $data['modelVersion'] ?? null;

        return new AiResponse(
            content: $text,
            provider: $this->name(),
            model: is_string($modelVersion) ? $modelVersion : $defaultModel,
            promptTokens: is_numeric($promptTokens) ? (int) $promptTokens : null,
            completionTokens: is_numeric($completionTokens) ? (int) $completionTokens : null,
            totalTokens: is_numeric($totalTokens) ? (int) $totalTokens : null,
            finishReason: is_string($finishReason) ? $finishReason : null,
            toolCalls: $toolCalls,
        );
    }

    /**
     * Gemini `functionResponse.response` MUST be an object. A tool result that is
     * already a JSON object is passed through; anything else (string, scalar,
     * JSON array) is wrapped as `{result: <content-string>}`.
     *
     * @return array<string, mixed>
     */
    private function toolResponsePayload(mixed $content): array
    {
        if (is_array($content)) {
            return $content;
        }

        if (! is_string($content)) {
            return ['result' => ''];
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) && array_keys($decoded) !== range(0, count($decoded) - 1)) {
            // Decoded to a JSON object (associative) → use it as the response.
            return $decoded;
        }

        return ['result' => $content];
    }

    /**
     * Decode a tool-call `arguments` JSON string into an associative array for
     * the Gemini `functionCall.args` field. A non-decodable / non-array value
     * falls back to an empty object.
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
