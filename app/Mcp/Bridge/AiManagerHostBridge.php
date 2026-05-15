<?php

declare(strict_types=1);

namespace App\Mcp\Bridge;

use App\Ai\AiManager;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;

/**
 * v7.0/W1.B — translates between the
 * {@see McpHostBridgeContract} the package orchestrator drives and
 * AskMyDocs's `App\Ai\AiManager`.
 *
 * The 30-line shape from the package README — we own:
 *
 *   - Translating `$turn->tools` (package contracts) into OpenAI-style
 *     `{type:'function', function:{name,description,parameters}}`
 *     payloads the AskMyDocs `AiManager::chatWithHistory()` already
 *     understands.
 *   - Translating provider tool_calls back into the package's
 *     `[{id,name,arguments:array}]` shape.
 *   - Declaring which providers support tool calling — currently the
 *     same allow-list AskMyDocs has used since v5.0 (OpenAI + OpenRouter).
 */
final class AiManagerHostBridge implements McpHostBridgeContract
{
    /**
     * Provider names that handle OpenAI-style function-calling. The
     * orchestrator uses {@see supportsToolCalling()} to short-circuit
     * early when the current host provider is tool-incapable.
     */
    private const array TOOL_CAPABLE_PROVIDERS = ['openai', 'openrouter'];

    public function __construct(private readonly AiManager $ai) {}

    public function chat(HostChatTurn $turn): HostChatResponse
    {
        $providerTools = $this->translateTools($turn->tools);

        $options = $turn->extras + ['tools' => $providerTools, 'tool_choice' => 'auto'];

        $systemPrompt = $this->extractSystemPrompt($turn->messages);
        $messages = $this->filterNonSystem($turn->messages);

        $response = $this->ai->chatWithHistory($systemPrompt, $messages, $options);

        return new HostChatResponse(
            content: $response->content === '' ? null : $response->content,
            toolCalls: $this->normalizeToolCalls($response->toolCalls),
            finishReason: $response->finishReason,
            usage: [
                'prompt_tokens' => $response->promptTokens ?? 0,
                'completion_tokens' => $response->completionTokens ?? 0,
                'total_tokens' => $response->totalTokens ?? 0,
            ],
            provider: $response->provider,
            model: $response->model,
        );
    }

    public function supportsToolCalling(): bool
    {
        return in_array($this->ai->provider()->name(), self::TOOL_CAPABLE_PROVIDERS, true);
    }

    /**
     * @param  array<int,McpToolContract> $tools
     * @return array<int,array<string,mixed>>
     */
    private function translateTools(array $tools): array
    {
        $providerTools = [];
        foreach ($tools as $tool) {
            $providerTools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->schema(),
                ],
            ];
        }

        return $providerTools;
    }

    /**
     * Provider responses arrive in OpenAI shape:
     *   [{id, type:'function', function:{name, arguments:string-or-array}}]
     * The orchestrator wants:
     *   [{id, name, arguments:array}]
     *
     * @return array<int,array{id:string,name:string,arguments:array<string,mixed>}>
     */
    private function normalizeToolCalls(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $call) {
            if (! is_array($call)) {
                continue;
            }
            $id = (string) ($call['id'] ?? ('tool_' . bin2hex(random_bytes(8))));
            $name = (string) ($call['function']['name'] ?? $call['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $rawArgs = $call['function']['arguments'] ?? $call['arguments'] ?? [];
            $arguments = $this->decodeArguments($rawArgs);

            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function decodeArguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<int,array<string,mixed>> $messages
     */
    private function extractSystemPrompt(array $messages): string
    {
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                return (string) ($msg['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  array<int,array<string,mixed>> $messages
     * @return array<int,array<string,mixed>>
     */
    private function filterNonSystem(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            static fn (array $msg): bool => ($msg['role'] ?? '') !== 'system',
        ));
    }
}
