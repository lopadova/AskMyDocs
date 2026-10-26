<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Ai\Tools\ChatToolSourceRegistry;
use App\Models\User;
use App\Services\Kb\Provenance\ToolFirewallVerdict;
use App\Support\SupportedLocale;

final class McpToolCallingService
{
    private const string SELF_REFUSAL_SENTINEL = '__NO_GROUNDED_ANSWER__';
    private const string CHAT_BUDGET = '_api_tool_chat_budget';

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
        $context[self::CHAT_BUDGET] = $this->chatBudget($context);
        if ($context[self::CHAT_BUDGET]['iterations_used'] >= $context[self::CHAT_BUDGET]['iterations_limit']) {
            return $this->budgetResponse([], 'chat_iteration_limit');
        }
        if (! $this->meetsToolCallingPrerequisites($user)) {
            return $this->providerTurn($systemPrompt, $messages, $options, $context);
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
            return $this->providerTurn(
                $systemPrompt,
                $messages,
                $this->withoutToolOptions($options),
                $context,
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
            return $this->providerTurn($systemPrompt, $messages, $options, $context);
        }

        $context['_api_tool_chat_turn_key'] ??= $this->chatTurnKey($messages, $user, $context);
        $chatHistory = $messages;
        $toolCallsSummary = [];
        $groundingFallback = null;

        while ($context[self::CHAT_BUDGET]['iterations_used'] < $context[self::CHAT_BUDGET]['iterations_limit']) {
            $turnOptions = $this->toolTurnOptions($options, $toolIndex);
            $llmResponse = $this->providerTurn($systemPrompt, $chatHistory, $turnOptions, $context);
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

            foreach ($toolCalls as $toolCallOrdinal => $toolCall) {
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

                $context['_api_tool_idempotency_key'] = isset($context['_api_tool_continuation_run_id'])
                    ? hash('sha256', 'api-tool-child:'.$context['_api_tool_continuation_run_id']."\0".$toolCall['id'])
                    : hash('sha256', $context['_api_tool_chat_turn_key']."\0".$toolCall['id']);
                $this->checkpointChatPending($context, $chatHistory, array_slice($toolCallsSummary, 0, -1), array_slice($toolCalls, $toolCallOrdinal));
                $charged = false;
                try {
                    $outcome = $this->physicalRemaining($context) === 0
                        ? new \App\Ai\Tools\ChatToolInvocationResult('error', ['error' => 'chat_physical_limit'], error: 'chat_physical_limit')
                        : $toolDefinition['source']->invoke(
                        tool: $toolDefinition['source_tool'],
                        arguments: $toolCall['arguments'],
                        user: $user,
                        context: $context + ['project_key' => $projectKey],
                    );
                    $charged = true;
                    $this->chargePhysical($context, $outcome);
                    $toolCallsSummary[$toolCallSummaryIndex] = $this->attachToolResultMetadata(
                        $toolCall,
                        $outcome->payload,
                        $outcome->status,
                        $outcome->error,
                        $outcome->metadata,
                    );
                    if ($outcome->requiresInteraction()) {
                        if ($outcome->status === 'task_accepted' && ($outcome->metadata['source'] ?? null) === 'api_tool') {
                            $this->attachChatHandoff((string) $outcome->metadata['run_id'], $user, [
                                'system_prompt' => $systemPrompt, 'history' => $chatHistory, 'options' => $options,
                                'context' => $context + ['project_key' => $projectKey], 'tool_call' => $toolCall,
                                'summary' => $toolCallsSummary,
                                'remaining' => array_slice($toolCalls, $toolCallOrdinal + 1),
                            ]);
                        }
                        return $this->interactionResponse($llmResponse, $toolCallsSummary, $outcome->metadata);
                    }
                    if ($outcome->status === 'completed' && $outcome->error === null) {
                        $groundingFallback = $this->groundingFallback($outcome->payload) ?? $groundingFallback;
                    }
                    $chatHistory[] = $this->toolResultMessage($toolCall, $outcome->payload);
                } catch (\Throwable $exception) {
                    if (! $charged) { $context[self::CHAT_BUDGET]['physical_used']++; }
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

        }

        return $this->budgetResponse($toolCallsSummary, 'chat_iteration_limit');
    }

    /** Resume the original provider tool-call protocol with the terminal API result. */
    public function resumeApiTool(\App\ApiTools\Models\ApiToolRun $run, User $user): AiResponse
    {
        $handoff = (array) $run->continuation;
        if (is_string(data_get($handoff, 'child_handoff.run_id'))) {
            $child = \App\ApiTools\Models\ApiToolRun::query()->forTenant($run->tenant_id)->findOrFail($handoff['child_handoff']['run_id']);
            \App\ApiTools\ToolContinuation::dispatchIfReady($child);
            return $this->interactionResponse(new AiResponse('', '', ''), $handoff['delegated_summary'] ?? [], ['source' => 'api_tool', 'run_id' => $child->id]);
        }
        $history = $handoff['history'];
        $summary = $handoff['summary'];
        $toolCall = $handoff['tool_call'];
        $context = $handoff['context'];
        $context['_api_tool_continuation_run_id'] = $run->id;
        $context[self::CHAT_BUDGET] = $this->chatBudget($context);
        if (! ($handoff['result_appended'] ?? false)) {
            $context[self::CHAT_BUDGET]['physical_used'] += max(0, (int) $run->physical_requests);
            $body = app(\App\ApiTools\ToolContinuation::class)->body($run);
            $history[] = $this->toolResultMessage($toolCall, $body);
            $last = count($summary) - 1;
            $summary[$last] = $this->attachToolResultMetadata($toolCall, $body,
                isset($body['error']) ? 'error' : 'completed', $body['error'] ?? null,
                ['source' => 'api_tool', 'run_id' => $run->id]);
        }
        $remaining = $handoff['remaining'];
        $handoff = array_merge($handoff, ['context' => $context, 'history' => $history, 'summary' => $summary, 'result_appended' => true]);
        $run->forceFill(['continuation' => $handoff])->save();
        $index = $this->buildToolIndex($user, $context['project_key'] ?? null);
        foreach ($remaining as $ordinal => $call) {
            $context['_api_tool_idempotency_key'] = hash('sha256', 'api-tool-child:'.$run->id."\0".$call['id']);
            $handoff = array_merge($handoff, ['context' => $context, 'pending_call' => ['id' => $call['id'], 'idempotency_key' => $context['_api_tool_idempotency_key']]]);
            $run->forceFill(['continuation' => $handoff])->save();
            $definition = $index[$call['name']] ?? null;
            try {
                $outcome = $this->physicalRemaining($context) === 0
                ? new \App\Ai\Tools\ChatToolInvocationResult('error', ['error' => 'chat_physical_limit'], error: 'chat_physical_limit')
                : ($definition === null
                ? new \App\Ai\Tools\ChatToolInvocationResult('error', ['error' => 'tool_no_longer_available'], error: 'tool_no_longer_available')
                : $definition['source']->invoke($definition['source_tool'], $call['arguments'], $user, $context));
            } catch (\Throwable) {
                $outcome = new \App\Ai\Tools\ChatToolInvocationResult('error', ['error' => 'chat_tool_failed'], error: 'chat_tool_failed');
            }
            $this->chargePhysical($context, $outcome);
            $summary[] = $this->attachToolResultMetadata($call, $outcome->payload, $outcome->status, $outcome->error, $outcome->metadata);
            if ($outcome->requiresInteraction()) {
                if ($outcome->status === 'task_accepted' && ($outcome->metadata['source'] ?? null) === 'api_tool') {
                    $this->attachChatHandoff((string) $outcome->metadata['run_id'], $user, [
                        'system_prompt' => $handoff['system_prompt'], 'history' => $history, 'options' => $handoff['options'],
                        'context' => $context, 'tool_call' => $call, 'summary' => $summary,
                        'remaining' => array_slice($remaining, $ordinal + 1),
                    ]);
                }
                return $this->interactionResponse(new AiResponse('', '', ''), $summary, $outcome->metadata);
            }
            $history[] = $this->toolResultMessage($call, $outcome->payload);
            // Persist each consumed sibling so a later provider failure cannot replay it.
            $handoff = array_merge($handoff, ['context' => $context, 'history' => $history, 'summary' => $summary,
                'result_appended' => true, 'remaining' => array_slice($remaining, $ordinal + 1)]);
            $run->forceFill(['continuation' => $handoff])->save();
        }
        $response = $this->chatWithTools($handoff['system_prompt'], $history, $handoff['options'], $user, $context);
        return new AiResponse($response->content, $response->provider, $response->model, $response->promptTokens,
            $response->completionTokens, $response->totalTokens, $response->finishReason, array_merge($summary, $response->toolCalls));
    }

    private function chatTurnKey(array $messages, User $user, array $context): string
    {
        $messageId = $context['user_message_id'] ?? null;
        if ($messageId === null && $user->exists && isset($context['conversation_id'])) {
            $messageId = \App\Models\Message::query()->forTenant(app(\App\Support\TenantContext::class)->current())
                ->where('conversation_id', $context['conversation_id'])->where('role', 'user')
                ->whereHas('conversation', fn ($query) => $query->where('user_id', $user->id))->max('id');
        }
        return hash('sha256', json_encode([$user->id, $context['conversation_id'] ?? null, $messageId ?? $messages], JSON_THROW_ON_ERROR));
    }

    private function checkpointChatPending(array $context, array $history, array $summary, array $remaining): void
    {
        if (! is_string($context['_api_tool_continuation_run_id'] ?? null)) { return; }
        $parent = \App\ApiTools\Models\ApiToolRun::query()->forTenant(app(\App\Support\TenantContext::class)->current())
            ->findOrFail($context['_api_tool_continuation_run_id']);
        $calls = [];
        foreach (array_merge($parent->continuation['summary'] ?? [], $summary) as $call) { $calls[$call['id']] = $call; }
        $parent->forceFill(['continuation' => array_merge((array) $parent->continuation, [
            'context' => $context, 'history' => $history, 'summary' => array_values($calls),
            'remaining' => $remaining, 'result_appended' => true,
            'pending_call' => ['id' => $remaining[0]['id'], 'idempotency_key' => $context['_api_tool_idempotency_key']],
        ])])->save();
    }

    private function attachChatHandoff(string $childId, User $user, array $payload): void
    {
        $parentId = $payload['context']['_api_tool_continuation_run_id'] ?? null;
        if (is_string($parentId) && $parentId !== $childId) {
            $parent = \App\ApiTools\Models\ApiToolRun::query()->forTenant(app(\App\Support\TenantContext::class)->current())->findOrFail($parentId);
            $calls = [];
            foreach (array_merge($parent->continuation['summary'] ?? [], $payload['summary']) as $call) { $calls[$call['id']] = $call; }
            $payload['summary'] = array_values($calls);
            app(\App\ApiTools\ToolContinuation::class)->delegateChat($parent, $childId, $user, $payload);
            return;
        }
        app(\App\ApiTools\ToolContinuation::class)->attachChat($childId, $user, $payload);
    }

    /** Limits belong to the original user turn, including every asynchronous handoff. */
    private function chatBudget(array $context): array
    {
        $stored = is_array($context[self::CHAT_BUDGET] ?? null) ? $context[self::CHAT_BUDGET] : [];
        $iterations = max(1, (int) config('mcp.tool_calling.max_iterations', 3));
        $physical = max(1, (int) config('api-tools.chat_physical_requests', config('api-tools.max_physical_requests', 100)));
        return [
            'iterations_limit' => min($iterations, max(1, (int) ($stored['iterations_limit'] ?? $iterations))),
            'iterations_used' => max(0, (int) ($stored['iterations_used'] ?? 0)),
            'physical_limit' => min($physical, max(1, (int) ($stored['physical_limit'] ?? $physical))),
            'physical_used' => max(0, (int) ($stored['physical_used'] ?? 0)),
        ];
    }

    private function physicalRemaining(array $context): int
    {
        $budget = $this->chatBudget($context);
        return max(0, $budget['physical_limit'] - $budget['physical_used']);
    }

    private function chargePhysical(array &$context, \App\Ai\Tools\ChatToolInvocationResult $outcome): void
    {
        // Queued work is charged exactly once from its durable wire counter on resume.
        $requests = ($outcome->status === 'task_accepted' && ($outcome->metadata['source'] ?? null) === 'api_tool') || $outcome->status === 'confirmation_required'
            || $outcome->error === 'chat_physical_limit' ? 0 : max(0, (int) ($outcome->metadata['physical_request_count'] ?? 1));
        $context[self::CHAT_BUDGET]['physical_used'] += $requests;
    }

    private function providerTurn(string $system, array $history, array $options, array &$context): AiResponse
    {
        $context[self::CHAT_BUDGET] = $this->chatBudget($context);
        if ($context[self::CHAT_BUDGET]['iterations_used'] >= $context[self::CHAT_BUDGET]['iterations_limit']) {
            return $this->budgetResponse([], 'chat_iteration_limit');
        }
        $context[self::CHAT_BUDGET]['iterations_used']++;
        // Debit before the external call so retries cannot reset the allowance.
        if (is_string($context['_api_tool_continuation_run_id'] ?? null)) {
            $run = \App\ApiTools\Models\ApiToolRun::query()->forTenant(app(\App\Support\TenantContext::class)->current())
                ->findOrFail($context['_api_tool_continuation_run_id']);
            $run->forceFill(['continuation' => array_merge((array) $run->continuation, ['context' => $context])])->save();
        }
        return $this->ai->chatWithHistory($system, $history, $options);
    }

    private function budgetResponse(array $summary, string $reason): AiResponse
    {
        return new AiResponse(__('The tool execution limit for this message was reached.'), 'server', 'tool-runtime',
            finishReason: $reason, toolCalls: $summary);
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
        $toolCall['task_id'] = $metadata['task_id'] ?? null;
        $toolCall['run_id'] = $metadata['run_id'] ?? null;
        $toolCall['task'] = is_array($metadata['task'] ?? null) ? $metadata['task'] : null;
        $toolCall['app'] = is_array($metadata['app'] ?? null) ? $metadata['app'] : null;
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
        $message = ($metadata['source'] ?? null) === 'api_tool'
            ? __('The tool is running. The answer will appear automatically.')
            : data_get($metadata, 'prompt.message');
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
