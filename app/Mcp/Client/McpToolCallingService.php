<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Mcp\Client\Registry\McpServerRegistry;
use App\Models\McpServer;
use App\Models\User;

final class McpToolCallingService
{
    /**
     * Provider names that can execute OpenAI-style function/tool calling with
     * the payload schema this service generates.
     */
    private const array TOOL_CAPABLE_PROVIDERS = ['openai', 'openrouter'];

    public function __construct(
        private readonly AiManager $ai,
        private readonly McpServerRegistry $registry,
        private readonly ToolInvoker $invoker,
        private readonly McpToolAuthorizer $authorizer,
    ) {}

    public function canHandleToolCalling(?User $user): bool
    {
        if (! $this->meetsToolCallingPrerequisites($user)) {
            return false;
        }

        return $this->buildToolIndex($user) !== [];
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

        $toolIndex = $this->buildToolIndex($user);
        if ($toolIndex === []) {
            return $this->ai->chatWithHistory($systemPrompt, $messages, $options);
        }

        $chatHistory = $messages;
        $maxIterations = max((int) config('mcp.tool_calling.max_iterations', 3), 1);
        $toolCallsSummary = [];

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $turnOptions = $this->toolTurnOptions($options, $toolIndex);
            $llmResponse = $this->ai->chatWithHistory($systemPrompt, $chatHistory, $turnOptions);
            $toolCalls = $this->normalizeProviderToolCalls($llmResponse->toolCalls);

            if ($toolCalls === []) {
                return $this->injectToolCalls($llmResponse, $toolCallsSummary);
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
                        error: "MCP tool [{$toolName}] is not configured for the current tenant.",
                    );
                    $toolCallsSummary[$toolCallSummaryIndex] = $this->injectToolErrorMetadata(
                        $toolCall,
                        "MCP tool [{$toolName}] is not configured for the current tenant.",
                    );
                    continue;
                }

                $toolCallsSummary[$toolCallSummaryIndex] = $this->appendInvokedToolCallMetadata(
                    toolCall: $toolCall,
                    server: $toolDefinition['server'],
                );
                $toolCall = $toolCallsSummary[$toolCallSummaryIndex];

                try {
                    $server = $toolDefinition['server'];
                    $toolResult = $this->invoker->invoke(
                        user: $user,
                        server: $server,
                        toolName: $toolName,
                        toolInput: $toolCall['arguments'],
                        context: $context,
                    );
                    $chatHistory[] = $this->toolResultMessage($toolCall, $toolResult);
                    $toolCallsSummary[$toolCallSummaryIndex] = $this->attachToolResultMetadata(
                        $toolCall,
                        $toolResult,
                        'ok',
                        null,
                    );
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
            $finalTurn,
            array_merge($toolCallsSummary, $this->normalizeProviderToolCalls($finalTurn->toolCalls)),
        );
    }

    private function meetsToolCallingPrerequisites(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if (! config('mcp.enabled', false)) {
            return false;
        }

        return in_array($this->providerName(), self::TOOL_CAPABLE_PROVIDERS, true);
    }

    private function providerName(): string
    {
        return $this->ai->provider()->name();
    }

    /**
     * @return array<string, array{server: McpServer, schema: array<int, array<string, mixed>>|array<string, mixed>}>
     */
    private function buildToolIndex(User $user): array
    {
        $toolIndex = [];
        $servers = $this->registry->activeServersForTenant();

        foreach ($servers as $server) {
            if (! $server instanceof McpServer) {
                continue;
            }

            $tools = $this->extractToolsFromServer($server);
            if ($tools === []) {
                continue;
            }

            $enabledTools = $server->enabled_tools_json;
            if (! is_array($enabledTools) || $enabledTools === []) {
                continue;
            }

            $allowAllTools = $enabledTools === ['*'];
            foreach ($tools as $tool) {
                if (! is_array($tool)) {
                    continue;
                }
                $name = (string) ($tool['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                if (! $allowAllTools && ! in_array($name, $enabledTools, true)) {
                    continue;
                }

                if (! $this->authorizer->canInvoke($user, $server, $name)) {
                    continue;
                }

                if (array_key_exists($name, $toolIndex)) {
                    continue;
                }

                $toolIndex[$name] = [
                    'server' => $server,
                    'schema' => $this->normalizeToolForProvider($tool, $name),
                ];
            }
        }

        return $toolIndex;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractToolsFromServer(McpServer $server): array
    {
        $handshake = $server->handshake_response_json;
        if (! is_array($handshake)) {
            return [];
        }

        $candidateTools = data_get($handshake, 'tools');
        if (! is_array($candidateTools)) {
            $candidateTools = data_get($handshake, 'capabilities.tools');
        }
        if (! is_array($candidateTools)) {
            $candidateTools = data_get($handshake, 'tool.list');
        }

        if (! is_array($candidateTools)) {
            return [];
        }

        if (array_is_list($candidateTools)) {
            return $candidateTools;
        }

        $tools = [];
        foreach ($candidateTools as $key => $tool) {
            if (is_array($tool)) {
                if (! array_key_exists('name', $tool)) {
                    $tool['name'] = (string) $key;
                }
                $tools[] = $tool;
            } elseif (is_string($tool)) {
                $tools[] = ['name' => $tool];
            }
        }

        return $tools;
    }

    /**
     * OpenAI-compatible function schema:
     * {
     *   type: 'function',
     *   function: {
     *     name: string,
     *     description: string,
     *     parameters: array
     *   }
     * }
     *
     * @param array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function normalizeToolForProvider(array $tool, string $name): array
    {
        $description = data_get($tool, 'description', '');
        $inputSchema = data_get($tool, 'inputSchema', data_get($tool, 'input_schema'));
        if (! is_array($inputSchema)) {
            $inputSchema = data_get($tool, 'parameters', []);
        }
        if (! is_array($inputSchema)) {
            $inputSchema = [];
        }
        if (! array_key_exists('type', $inputSchema)) {
            $inputSchema['type'] = 'object';
        }
        if (! array_key_exists('properties', $inputSchema)) {
            $inputSchema['properties'] = [];
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => is_string($description) ? $description : '',
                'parameters' => $inputSchema,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, array{server: McpServer, schema: array<string, mixed>}>  $toolIndex
     * @return array<string, mixed>
     */
    private function toolTurnOptions(array $options, array $toolIndex): array
    {
        $toolsPayload = array_map(
            static fn(array $toolDefinition): array => $toolDefinition['schema'],
            array_values($toolIndex),
        );

        $options['tools'] = $toolsPayload;
        if (! array_key_exists('tool_choice', $options)) {
            $options['tool_choice'] = config('mcp.tool_calling.default_tool_choice', 'auto');
        }

        return $options;
    }

    /**
     * @param  mixed  $rawToolCalls
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

            $id = (string) data_get($toolCall, 'id', 'tool_' . bin2hex(random_bytes(8)));
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
     * @param  mixed  $rawArguments
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
            'id' => (string) ($toolCall['id'] ?? ('tool_' . bin2hex(random_bytes(8)))),
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
            static fn(array $toolCall): array => [
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
            'tool_call_id' => $toolCall['id'] ?? ('tool_' . bin2hex(random_bytes(8))),
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

    private function appendInvokedToolCallMetadata(array $toolCall, McpServer $server): array
    {
        $toolCall['server_id'] = $server->id;
        $toolCall['server_name'] = $server->name;
        return $toolCall;
    }

    private function attachToolResultMetadata(
        array $toolCall,
        mixed $result,
        string $status,
        ?string $error,
    ): array {
        $toolCall['status'] = $status;
        $toolCall['result'] = is_array($result) ? ['size' => count($result)] : null;
        $toolCall['error'] = $error;
        return $toolCall;
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
}
