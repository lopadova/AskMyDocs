<?php

declare(strict_types=1);

namespace App\Mcp\Adapters;

use App\Ai\AiManager;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;

/**
 * v7.0/W6.3 — host adapter for the package's
 * {@see McpHostBridgeContract}.
 *
 * Translates the orchestrator's provider-agnostic per-turn shape
 * (`HostChatTurn`) into the host's existing `AiManager::chatWithHistory()`
 * call, then normalises the host's `AiResponse` back into the
 * package's `HostChatResponse`.
 *
 * Tool calling is OpenAI-shape — the host's OpenAI / OpenRouter
 * providers already accept `options.tools` + `options.tool_choice`
 * verbatim, so the bridge just maps the package's
 * `McpToolContract[]` catalog into the OpenAI function shape.
 *
 * System prompts come from `$turn->messages[0]` when its role is
 * `system`, falling back to `$turn->extras['system_prompt']` for
 * orchestrators that prefer to carry the preamble in extras. Tenant
 * scope is consulted only when the host's AiManager needs it
 * (current providers don't); the bridge keeps the value for future
 * tenant-aware routing.
 */
final class HostBridge implements McpHostBridgeContract
{
    /**
     * Providers in the host's `AiManager` stack that expose
     * OpenAI-style function calling. Anthropic / Gemini / Regolo do
     * tool calling differently — those providers ARE supported by
     * the host but NOT through the MCP orchestrator's OpenAI-shape
     * tool catalog (yet).
     */
    private const array TOOL_CAPABLE_PROVIDERS = ['openai', 'openrouter'];

    public function __construct(
        private readonly AiManager $ai,
    ) {}

    public function chat(HostChatTurn $turn): HostChatResponse
    {
        [$systemPrompt, $history] = $this->splitMessages($turn);

        $options = $turn->extras;
        // The orchestrator carries provider-tuning extras (temperature,
        // seed, …) verbatim. Only INJECT the tool catalog when there's
        // something to inject — sending `"tools": []` to OpenAI /
        // OpenRouter behaves differently than omitting the field (and
        // some providers outright reject empty arrays). When the catalog
        // is empty the bridge passes the request through as a plain
        // chat completion.
        $toolsPayload = $this->buildToolsPayload($turn->tools);
        if ($toolsPayload !== []) {
            $options['tools'] = $toolsPayload;
            if (! isset($options['tool_choice'])) {
                $options['tool_choice'] = 'auto';
            }
        }

        $response = $this->ai->chatWithHistory($systemPrompt, $history, $options);

        return new HostChatResponse(
            content: $response->content !== '' ? $response->content : null,
            toolCalls: $this->normaliseToolCalls($response->toolCalls),
            finishReason: $response->finishReason,
            usage: [
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'total_tokens' => $response->totalTokens,
            ],
            provider: $response->provider,
            model: $response->model,
        );
    }

    public function supportsToolCalling(): bool
    {
        return in_array($this->providerName(), self::TOOL_CAPABLE_PROVIDERS, true);
    }

    /**
     * @return array{0: string, 1: array<int, array<string,mixed>>}
     */
    private function splitMessages(HostChatTurn $turn): array
    {
        $messages = $turn->messages;
        $systemPrompt = '';
        if ($messages !== [] && ($messages[0]['role'] ?? '') === 'system') {
            $systemPrompt = (string) ($messages[0]['content'] ?? '');
            $messages = array_slice($messages, 1);
        } elseif (isset($turn->extras['system_prompt']) && is_string($turn->extras['system_prompt'])) {
            $systemPrompt = $turn->extras['system_prompt'];
        }
        return [$systemPrompt, array_values($messages)];
    }

    /**
     * @param  array<int, McpToolContract>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function buildToolsPayload(array $tools): array
    {
        $payload = [];
        foreach ($tools as $tool) {
            $payload[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->schema(),
                ],
            ];
        }
        return $payload;
    }

    /**
     * @param  array<int, mixed>  $rawToolCalls
     * @return array<int, array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function normaliseToolCalls(array $rawToolCalls): array
    {
        $out = [];
        foreach ($rawToolCalls as $call) {
            if (! is_array($call)) {
                continue;
            }
            $id = (string) ($call['id'] ?? 'tool_' . bin2hex(random_bytes(6)));
            $name = (string) data_get($call, 'function.name', $call['name'] ?? '');
            if ($name === '') {
                continue;
            }
            // OpenAI ships arguments as a JSON-string under
            // `function.arguments`; the host's AiResponse already
            // mirrors that shape. Decode lazily so the bridge can
            // surface a parse error to the orchestrator (it'll
            // reject the tool call gracefully) instead of crashing.
            $argsRaw = data_get($call, 'function.arguments', $call['arguments'] ?? '{}');
            if (is_array($argsRaw)) {
                $args = $argsRaw;
            } else {
                $decoded = json_decode((string) $argsRaw, true);
                $args = is_array($decoded) ? $decoded : [];
            }
            $out[] = ['id' => $id, 'name' => $name, 'arguments' => $args];
        }
        return $out;
    }

    private function providerName(): string
    {
        // The host's active AI provider lives at `ai.default`
        // (driven by `AI_PROVIDER` env in `config/ai.php`). NOT
        // `ai.provider` — that key doesn't exist and would silently
        // default to OpenAI even when the operator selected
        // Anthropic / Gemini / Regolo.
        return (string) (config('ai.default') ?? 'openai');
    }
}
