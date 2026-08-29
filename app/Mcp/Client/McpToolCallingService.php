<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use App\Agent\Tools\ApiToolRequestContext;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Mcp\Client\Registry\McpServerRegistry;
use App\Models\McpServer;
use App\Models\User;
use App\Services\Kb\Provenance\ToolFirewallVerdict;
use App\Support\TenantContext;
use App\Support\SupportedLocale;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolRegistry;

final class McpToolCallingService
{
    /**
     * Provider names that can execute OpenAI-style function/tool calling with
     * the payload schema this service generates. openai + openrouter speak the
     * shape natively over raw Http:: /chat/completions; anthropic + gemini have a
     * raw-Http with-tools path that translates this OpenAI-shaped tools + history
     * into their native Messages / generateContent APIs and back to the normalized
     * tool-call shape (see {@see \App\Ai\Providers\AnthropicProvider} +
     * {@see \App\Ai\Providers\GeminiProvider}).
     */
    private const array TOOL_CAPABLE_PROVIDERS = ['openai', 'openrouter', 'anthropic', 'gemini'];

    public function __construct(
        private readonly AiManager $ai,
        private readonly McpServerRegistry $registry,
        private readonly ToolInvoker $invoker,
        private readonly McpToolAuthorizer $authorizer,
        private readonly ApiToolRegistry $apiToolRegistry,
        private readonly ApiToolExecutor $apiToolExecutor,
        private readonly TenantContext $tenantContext,
        private readonly ApiToolRequestContext $apiToolRequestContext,
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

        // ADR 0028 phase 3 - externally-authored grounding may be QUOTED but
        // must never influence a tool call. IMAP ingests content written by
        // anyone who can send an email; that content becomes grounding on a
        // platform that also exposes tools to the model, and nothing in
        // between distinguishes a colleague's runbook from a stranger's
        // instructions.
        //
        // The turn still gets its answer from the same context and the same
        // citations. Only the tools are withheld, because quoting is what the
        // corpus is for and acting is what an attacker wants.
        //
        // The verdict is computed by the caller, the only layer that has seen
        // the retrieval result. An absent or unrecognised verdict reads as
        // ALLOWED, so a deployment that has not wired it through behaves
        // exactly as it did before (R43).
        $verdict = ToolFirewallVerdict::fromArray(
            is_array($context['provenance_firewall'] ?? null) ? $context['provenance_firewall'] : null,
        );

        if (! $verdict->toolsAllowed) {
            return $this->ai->chatWithHistory(
                $systemPrompt,
                $messages,
                $this->withoutToolOptions($options),
            );
        }

        $projectKey = isset($context['project_key']) && is_string($context['project_key'])
            ? $context['project_key']
            : null;
        if ($user instanceof User) {
            // The authenticated preference is authoritative; callers cannot
            // override the run language with an arbitrary context value.
            $context['locale'] = SupportedLocale::normalize($user->locale);
        }

        $toolIndex = $this->buildToolIndex($user, $projectKey);
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

                $isApiTool = isset($toolDefinition['api_route_id']);
                if (! $isApiTool) {
                    $toolCallsSummary[$toolCallSummaryIndex] = $this->appendInvokedToolCallMetadata(
                        toolCall: $toolCall,
                        server: $toolDefinition['server'],
                    );
                    $toolCall = $toolCallsSummary[$toolCallSummaryIndex];
                }

                try {
                    $toolResult = $isApiTool
                        ? $this->invokeApiTool((int) $toolDefinition['api_route_id'], $toolCall['arguments'], $context)
                        : $this->invoker->invoke(
                            user: $user,
                            server: $toolDefinition['server'],
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

        // Tool calling is available when EITHER external MCP servers are enabled
        // OR the API-connector live tools are enabled — the latter is an
        // independent tool source and must not be gated behind MCP.
        $mcpEnabled = (bool) config('mcp.enabled', false);
        $apiToolsEnabled = (bool) config('connector-api.chat_tools.enabled', true);
        if (! $mcpEnabled && ! $apiToolsEnabled) {
            return false;
        }

        return in_array($this->providerName(), self::TOOL_CAPABLE_PROVIDERS, true);
    }

    private function providerName(): string
    {
        return $this->ai->provider()->name();
    }

    /**
     * @return array<string, array{server?: McpServer, api_route_id?: int, schema: array<int, array<string, mixed>>|array<string, mixed>}>
     */
    private function buildToolIndex(User $user, ?string $projectKey = null): array
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

        $this->mergeApiTools($toolIndex, $projectKey);

        return $toolIndex;
    }

    /**
     * Merge the API-connector live tools (spec "Connettore API") into the index
     * alongside the external MCP-server tools. Each API entry carries an
     * `api_route_id` instead of a `server`; the dispatch loop routes it to the
     * {@see ApiToolExecutor}. Config-gated (R43) and tenant-scoped (R30); an
     * MCP tool already in the index wins a name collision (first-registered).
     *
     * @param  array<string, array<string, mixed>>  $toolIndex
     */
    private function mergeApiTools(array &$toolIndex, ?string $projectKey): void
    {
        if (! (bool) config('connector-api.chat_tools.enabled', true)) {
            return;
        }

        $tenantId = $this->tenantContext->current();

        foreach ($this->apiToolRegistry->activeToolsForTenant($tenantId, $projectKey) as $apiTool) {
            $name = (string) ($apiTool['name'] ?? '');
            if ($name === '' || array_key_exists($name, $toolIndex)) {
                continue;
            }

            $toolIndex[$name] = [
                'api_route_id' => (int) $apiTool['route_id'],
                'schema' => $this->normalizeToolForProvider($apiTool['definition'], $name),
            ];
        }
    }

    /**
     * Execute an API-connector tool call server-side via the package executor.
     * The route is reloaded tenant-scoped (R30) — it may have been disabled
     * between index build and invocation. Returns the sanitised tool_result
     * (or a structured error the LLM can explain, never a throw — R14).
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function invokeApiTool(int $routeId, array $arguments, array $context): array
    {
        $route = ApiRoute::query()
            ->forTenant($this->tenantContext->current())
            ->with('parameters')
            ->find($routeId);

        if ($route === null) {
            return ['error' => 'This API tool is no longer available.'];
        }

        $prepared = $this->apiToolRequestContext->apply($route, $arguments, $context);

        return $this->apiToolExecutor->execute(
            $prepared['route'],
            $prepared['arguments'],
            $prepared['context'],
        );
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
    /**
     * Strip every option that could put tools on the wire.
     *
     * No caller passes these today -- both chat controllers send an empty
     * options array -- so this changes nothing now. It is here because
     * "withheld" has to mean withheld regardless of what a future caller
     * hands in: a control that depends on every present and future call site
     * choosing not to pass `tools` is not a control, and the failure would be
     * silent, since a turn that quietly kept its tools looks exactly like one
     * that was never blocked.
     *
     * Both the OpenAI-shaped keys and their deprecated predecessors, because
     * a provider that still honours `functions` would honour it here too.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withoutToolOptions(array $options): array
    {
        unset(
            $options['tools'],
            $options['tool_choice'],
            $options['functions'],
            $options['function_call'],
        );

        return $options;
    }

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
