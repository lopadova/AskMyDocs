<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Ai\Tools\ChatToolSourceRegistry;
use App\Models\User;

final class McpToolCallingService
{
    private const string SELF_REFUSAL_SENTINEL = '__NO_GROUNDED_ANSWER__';

    /**
     * Provider names that can execute OpenAI-style function/tool calling with
     * the payload schema this service generates.
     */
    private const array TOOL_CAPABLE_PROVIDERS = ['openai', 'openrouter'];

    public function __construct(
        private readonly AiManager $ai,
        private readonly ChatToolSourceRegistry $sources,
    ) {}

    public function canHandleToolCalling(?User $user, ?string $projectKey = null): bool
    {
        if (! $this->meetsToolCallingPrerequisites($user)) {
            return false;
        }

        return $this->buildToolIndex($user, $projectKey) !== [];
    }

    /**
     * Execute a chat call with MCP tools when enabled and supported.
     *
     * @param  list<array{role: string, content: string, tool_calls?: mixed, tool_call_id?: mixed, name?: mixed}>  $messages
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $context
     */
    public function chatWithTools(
        string $systemPrompt,
        array $messages,
        array $options = [],
        ?User $user = null,
        array $context = [],
    ): AiResponse {
        if (! $this->meetsToolCallingPrerequisites($user)) {
            return $this->ai->chatWithHistory($systemPrompt, $messages, $options);
        }

        $projectKey = is_string($context['project_key'] ?? null) ? $context['project_key'] : null;
        $toolIndex = $this->buildToolIndex($user, $projectKey);
        if ($toolIndex === []) {
            return $this->ai->chatWithHistory($systemPrompt, $messages, $options);
        }

        $chatHistory = $messages;
        $maxIterations = max((int) config('mcp.tool_calling.max_iterations', 3), 1);
        $toolCallsSummary = [];
        $groundingFallback = null;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $turnOptions = $this->toolTurnOptions($options, $toolIndex);
            $llmResponse = $this->ai->chatWithHistory($systemPrompt, $chatHistory, $turnOptions);
            $toolCalls = $this->normalizeProviderToolCalls($llmResponse->toolCalls);

            if ($toolCalls === []) {
                return $this->injectToolCalls(
                    $this->withGroundingFallback($llmResponse, $groundingFallback),
                    $toolCallsSummary,
                );
            }

            $chatHistory[] = [
                'role' => 'assistant',
                'content' => (string) ($llmResponse->content ?? ''),
                'tool_calls' => $this->toProviderToolCalls($toolCalls),
            ];

            foreach ($toolCalls as $toolCall) {
                $toolCallSummaryIndex = count($toolCallsSummary);
                $toolCallsSummary[] = $this->normalizeToolCallResultShape($toolCall);

                $toolCall = $toolCallsSummary[$toolCallSummaryIndex];
                $toolName = $toolCall['name'];
                $toolDefinition = $toolIndex[$toolName] ?? null;
                if (! is_array($toolDefinition)) {
                    $chatHistory[] = $this->toolErrorMessage(
                        id: $toolCall['id'],
                        toolName: $toolName,
                        error: "Chat tool [{$toolName}] is not configured for the current tenant.",
                    );
                    $toolCallsSummary[$toolCallSummaryIndex] = $this->injectToolErrorMetadata(
                        $toolCall,
                        "Chat tool [{$toolName}] is not configured for the current tenant.",
                    );

                    continue;
                }

                $toolCallsSummary[$toolCallSummaryIndex] = $this->appendInvokedToolCallMetadata(
                    toolCall: $toolCall,
                    toolDefinition: $toolDefinition,
                );
                $toolCall = $toolCallsSummary[$toolCallSummaryIndex];

                try {
                    $outcome = $toolDefinition['source']->invoke(
                        tool: $toolDefinition['source_tool'],
                        arguments: $toolCall['arguments'],
                        user: $user,
                        context: $context + ['project_key' => $projectKey],
                    );
                    $toolCallsSummary[$toolCallSummaryIndex] = $this->attachToolResultMetadata(
                        $toolCall,
                        $outcome->payload,
                        $outcome->status,
                        $outcome->error,
                        $outcome->metadata,
                    );
                    if ($outcome->requiresInteraction()) {
                        return $this->interactionResponse($llmResponse, $toolCallsSummary, $outcome->metadata);
                    }
                    if ($outcome->status === 'completed' && $outcome->error === null) {
                        $groundingFallback = $this->groundingFallback($outcome->payload) ?? $groundingFallback;
                    }
                    $chatHistory[] = $this->toolResultMessage($toolCall, $outcome->payload);
                } catch (\Throwable $exception) {
                    $chatHistory[] = $this->toolErrorMessage(
                        id: $toolCall['id'],
                        toolName: $toolName,
                        error: $exception->getMessage(),
                    );
                    $toolCallsSummary[$toolCallSummaryIndex] = $this->attachToolResultMetadata(
                        $toolCall,
                        ['error' => $exception->getMessage()],
                        'error',
                        $exception->getMessage(),
                    );
                }
            }

            if ($iteration === $maxIterations - 1) {
                break;
            }
        }

        $finalTurn = $this->ai->chatWithHistory($systemPrompt, $chatHistory, $options);

        return $this->injectToolCalls(
            $this->withGroundingFallback($finalTurn, $groundingFallback),
            array_merge($toolCallsSummary, $this->normalizeProviderToolCalls($finalTurn->toolCalls)),
        );
    }

    private function meetsToolCallingPrerequisites(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return in_array($this->providerName(), self::TOOL_CAPABLE_PROVIDERS, true);
    }

    private function providerName(): string
    {
        return $this->ai->provider()->name();
    }

    /** @return array<string, array<string, mixed>> */
    private function buildToolIndex(User $user, ?string $projectKey = null): array
    {
        return $this->sources->toolIndex($user, $projectKey);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, array<string, mixed>>  $toolIndex
     * @return array<string, mixed>
     */
    private function toolTurnOptions(array $options, array $toolIndex): array
    {
        $toolsPayload = array_map(
            static fn (array $toolDefinition): array => $toolDefinition['schema'],
            array_values($toolIndex),
        );

        $options['tools'] = $toolsPayload;
        if (! array_key_exists('tool_choice', $options)) {
            $options['tool_choice'] = config('mcp.tool_calling.default_tool_choice', 'auto');
        }

        return $options;
    }

    /**
     * @return list<array{id:string,name:string,arguments:array,arguments_json:string}>
     */
    private function normalizeProviderToolCalls(mixed $rawToolCalls): array
    {
        if (! is_array($rawToolCalls)) {
            return [];
        }

        $toolCalls = [];
        foreach ($rawToolCalls as $toolCall) {
            if (! is_array($toolCall)) {
                continue;
            }

            $id = (string) data_get($toolCall, 'id', 'tool_'.bin2hex(random_bytes(8)));
            $name = (string) data_get($toolCall, 'function.name', '');
            if ($name === '') {
                $name = (string) data_get($toolCall, 'name', '');
            }
            if ($name === '') {
                continue;
            }

            $arguments = $this->normalizeToolArguments(
                data_get($toolCall, 'function.arguments', data_get($toolCall, 'arguments')),
            );

            $toolCalls[] = [
                'id' => $id,
                'name' => $name,
                'status' => 'pending',
                'arguments' => $arguments,
                'arguments_json' => $this->argumentsToJson($arguments),
                'server_id' => null,
                'server_name' => null,
                'error' => null,
                'result' => null,
            ];
        }

        return $toolCalls;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeToolArguments(mixed $rawArguments): array
    {
        if (is_array($rawArguments)) {
            return $rawArguments;
        }

        if (is_bool($rawArguments) || is_int($rawArguments) || is_float($rawArguments)) {
            return ['value' => (string) $rawArguments];
        }

        if (! is_string($rawArguments)) {
            return [];
        }

        $decoded = json_decode($rawArguments, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['value' => $rawArguments];
        }

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /**
     * @return array{id:string,name:string,status:string,arguments:array,arguments_json:string,server_id:?int,server_name:?string,error:?string,result:?array}
     */
    private function normalizeToolCallResultShape(array $toolCall): array
    {
        return [
            'id' => (string) ($toolCall['id'] ?? ('tool_'.bin2hex(random_bytes(8)))),
            'name' => (string) ($toolCall['name'] ?? ''),
            'status' => (string) ($toolCall['status'] ?? 'pending'),
            'arguments' => is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [],
            'arguments_json' => (string) ($toolCall['arguments_json'] ?? '{}'),
            'server_id' => $toolCall['server_id'] ?? null,
            'server_name' => $toolCall['server_name'] ?? null,
            'error' => $toolCall['error'] ?? null,
            'result' => $toolCall['result'] ?? null,
        ];
    }

    private function argumentsToJson(array $arguments): string
    {
        $json = json_encode($arguments, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '{}';
        }

        return $json;
    }

    /**
     * @param  list<array{id:string,name:string,status:string,arguments:array,arguments_json:string}>  $toolCalls
     * @return list<array<string, mixed>>
     */
    private function toProviderToolCalls(array $toolCalls): array
    {
        return array_map(
            static fn (array $toolCall): array => [
                'id' => (string) $toolCall['id'],
                'type' => 'function',
                'function' => [
                    'name' => (string) $toolCall['name'],
                    'arguments' => (string) $toolCall['arguments_json'],
                ],
            ],
            $toolCalls,
        );
    }

    /**
     * @return array{
     *   id: string,
     *   name: string,
     *   status: string,
     *   tool_call_id: string,
     *   content: string
     * }
     */
    private function toolErrorMessage(string $id, string $toolName, string $error): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $id,
            'name' => $toolName,
            'content' => $this->encodeToolResult([
                'error' => $error,
            ]),
        ];
    }

    private function toolResultMessage(array $toolCall, mixed $result): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $toolCall['id'] ?? ('tool_'.bin2hex(random_bytes(8))),
            'name' => $toolCall['name'],
            'content' => $this->encodeToolResult($result),
        ];
    }

    private function encodeToolResult(mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '';
        }

        return $json;
    }

    /** @param array<string, mixed> $toolDefinition */
    private function appendInvokedToolCallMetadata(array $toolCall, array $toolDefinition): array
    {
        $provenance = is_array($toolDefinition['provenance'] ?? null)
            ? $toolDefinition['provenance']
            : [];
        $toolCall['source'] = $toolDefinition['source_key'] ?? null;
        $toolCall['server_id'] = $provenance['server_id'] ?? null;
        $toolCall['server_name'] = $provenance['server_name'] ?? null;
        $toolCall['provenance'] = $provenance;

        return $toolCall;
    }

    private function attachToolResultMetadata(
        array $toolCall,
        mixed $result,
        string $status,
        ?string $error,
        array $metadata = [],
    ): array {
        $toolCall['status'] = $status;
        $toolCall['result'] = is_array($result) ? ['size' => count($result)] : null;
        $toolCall['error'] = $error;
        $toolCall['source'] = $metadata['source'] ?? $toolCall['source'] ?? null;
        $toolCall['server_id'] = $metadata['server_id'] ?? $toolCall['server_id'] ?? null;
        $toolCall['server_name'] = $metadata['server_name'] ?? $toolCall['server_name'] ?? null;
        $toolCall['pending_interaction_id'] = $metadata['pending_interaction_id'] ?? null;
        $toolCall['prompt'] = is_array($metadata['prompt'] ?? null) ? $metadata['prompt'] : null;
        $toolCall['provenance'] = array_filter(array_merge(
            is_array($toolCall['provenance'] ?? null) ? $toolCall['provenance'] : [],
            $metadata,
        ), static fn (mixed $value): bool => $value !== null);

        return $toolCall;
    }

    /**
     * @param  list<array<string, mixed>>  $toolCallsSummary
     * @param  array<string, mixed>  $metadata
     */
    private function interactionResponse(AiResponse $response, array $toolCallsSummary, array $metadata): AiResponse
    {
        $message = data_get($metadata, 'prompt.message');
        if (! is_string($message) || $message === '') {
            $message = $response->content !== ''
                ? $response->content
                : 'This tool call requires your input before the conversation can continue.';
        }

        return new AiResponse(
            content: $message,
            provider: $response->provider,
            model: $response->model,
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
            totalTokens: $response->totalTokens,
            finishReason: 'tool_interaction',
            toolCalls: $toolCallsSummary,
        );
    }

    private function injectToolErrorMetadata(array $toolCall, string $error): array
    {
        $toolCall['status'] = 'error';
        $toolCall['error'] = $error;

        return $toolCall;
    }

    private function injectToolCalls(AiResponse $response, array $toolCallsSummary): AiResponse
    {
        return new AiResponse(
            content: $response->content,
            provider: $response->provider,
            model: $response->model,
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
            totalTokens: $response->totalTokens,
            finishReason: $response->finishReason,
            toolCalls: $toolCallsSummary,
        );
    }

    private function withGroundingFallback(AiResponse $response, ?string $fallback): AiResponse
    {
        if ($fallback === null || trim($response->content) !== self::SELF_REFUSAL_SENTINEL) {
            return $response;
        }

        return $response->withContent($fallback);
    }

    private function groundingFallback(mixed $payload): ?string
    {
        $candidate = data_get($payload, 'artifact.text');
        if (! is_string($candidate) || trim($candidate) === '') {
            $candidate = data_get($payload, 'text');
        }
        if (! is_string($candidate) || trim($candidate) === '') {
            $candidate = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        }
        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        return mb_substr(trim($candidate), 0, 12_000);
    }
}
