<?php

declare(strict_types=1);

namespace App\ApiTools;

use App\Agent\AgentEventPublisher;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentRunDispatcher;
use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Agent\Tools\AgentToolDefinition;
use App\ApiTools\Jobs\ResumeApiTool;
use App\ApiTools\Models\ApiToolRun;
use App\Mcp\Client\McpToolCallingService;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Widget\WidgetPiiMasker;
use Illuminate\Support\Facades\DB;

/** Durable, encrypted handoffs owned by the API runtime, independent of MCP. */
final readonly class ToolContinuation
{
    public function attachAgent(string $toolRunId, AgentRun $agent, array $payload): void
    {
        $tool = DB::transaction(function () use ($toolRunId, $agent, $payload): ApiToolRun {
            $tool = ApiToolRun::query()->forTenant($agent->tenant_id)->whereKey($toolRunId)->lockForUpdate()->firstOrFail();
            $owner = AgentRun::query()->forTenant($agent->tenant_id)->whereKey($agent->id)->lockForUpdate()->firstOrFail();
            if ($owner->isTerminal() || $tool->project_key !== ($owner->project_key ?? '')) {
                throw new \DomainException('api_tool_continuation_scope_mismatch');
            }
            $context = AgentExecutionContext::fromArray($tool->context);
            $context->assertMatches($owner);
            if (in_array(data_get($tool->continuation, 'type'), ['agent', 'chat'], true) && (data_get($tool->continuation, 'type') !== 'agent' || data_get($tool->continuation, 'agent_run_id') !== $owner->id || data_get($tool->continuation, 'execution_id') !== $payload['execution_id'])) {
                throw new \DomainException('api_tool_continuation_already_attached');
            }
            if (data_get($tool->continuation, 'type') === 'agent' && $tool->resumed_at !== null) { return $tool; }
            $tool->forceFill(['resumed_at' => null, 'continuation' => array_merge((array) $tool->continuation, ['type' => 'agent', 'agent_run_id' => $owner->id], $payload)])->save();
            $checkpoint = (array) $owner->result_json;
            if (isset($payload['wave_id'])) {
                $checkpoint['pending_api_tools'][$tool->id] = true;
            } else {
                $checkpoint['pending_api_tool'] = ['run_id' => $tool->id];
            }
            $owner->forceFill([
                'status' => AgentRun::STATUS_WAITING_API_TOOL,
                'result_json' => $checkpoint,
            ])->save();
            return $tool;
        });
        $agent->refresh();
        app(AgentEventPublisher::class)->publish($agent, 'run.tool_pending', data: ['tool_run_id' => $toolRunId]);
        self::dispatchIfReady($tool);
    }

    /** Close registration only after every sibling handoff is durable. */
    public function finishAgentWave(AgentRun $agent): void
    {
        $ready = DB::transaction(function () use ($agent): ?AgentRun {
            $owner = AgentRun::query()->forTenant($agent->tenant_id)->whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $checkpoint = (array) $owner->result_json;
            if ($owner->isTerminal() || ! ($checkpoint['wave_dispatching'] ?? false)) { return null; }
            $checkpoint['wave_dispatching'] = false;
            $waiting = (array) ($checkpoint['pending_api_tools'] ?? []) !== [];
            $finishedWaves = $waiting ? [] : $this->completeWaves($checkpoint);
            $owner->forceFill(['status' => $waiting ? AgentRun::STATUS_WAITING_API_TOOL : AgentRun::STATUS_QUEUED,
                'result_json' => $checkpoint])->save();
            foreach ($finishedWaves as $wave) { app(AgentEventPublisher::class)->publish($owner, 'wave.completed', data: $wave); }
            return $waiting ? null : $owner;
        });
        $agent->refresh();
        if ($ready !== null) { DB::afterCommit(fn () => app(AgentRunDispatcher::class)->enqueue($ready)); }
    }

    public function attachChat(string $toolRunId, User $user, array $payload): void
    {
        $tool = DB::transaction(function () use ($toolRunId, $user, $payload): ApiToolRun {
            $tool = ApiToolRun::query()->forTenant(app(\App\Support\TenantContext::class)->current())->whereKey($toolRunId)->lockForUpdate()->firstOrFail();
            $context = AgentExecutionContext::fromArray($tool->context);
            $conversation = Conversation::query()->forTenant($tool->tenant_id)->whereKey($payload['context']['conversation_id'] ?? null)->where('user_id', $user->id)->first();
            if ($conversation === null || $context->actorType !== 'user' || $context->actorId !== (string) $user->id) {
                throw new \DomainException('api_tool_continuation_scope_mismatch');
            }
            if (in_array(data_get($tool->continuation, 'type'), ['agent', 'chat'], true) && (data_get($tool->continuation, 'type') !== 'chat' || data_get($tool->continuation, 'conversation_id') !== $conversation->id || data_get($tool->continuation, 'tool_call.id') !== data_get($payload, 'tool_call.id'))) {
                throw new \DomainException('api_tool_continuation_already_attached');
            }
            if (data_get($tool->continuation, 'type') === 'chat' && $tool->resumed_at !== null) { return $tool; }
            $tool->forceFill(['resumed_at' => null, 'continuation' => array_merge((array) $tool->continuation, ['type' => 'chat', 'user_id' => $user->id, 'conversation_id' => $conversation->id], $payload)])->save();
            return $tool;
        });
        self::dispatchIfReady($tool);
    }

    /** Child attachment and the parent's delegation marker must become durable together. */
    public function delegateChat(ApiToolRun $parent, string $childId, User $user, array $payload): void
    {
        DB::transaction(function () use ($parent, $childId, $user, $payload): void {
            $locked = ApiToolRun::query()->forTenant($parent->tenant_id)->whereKey($parent->id)->lockForUpdate()->firstOrFail();
            $existing = data_get($locked->continuation, 'child_handoff.run_id');
            if (is_string($existing) && $existing !== $childId) { throw new \DomainException('api_tool_continuation_already_delegated'); }
            $this->attachChat($childId, $user, $payload);
            $continuation = array_merge((array) $locked->continuation, [
                'child_handoff' => ['run_id' => $childId, 'tool_call_id' => $payload['tool_call']['id']],
                'delegated_summary' => $payload['summary'], 'remaining' => $payload['remaining'],
            ]);
            unset($continuation['pending_call']);
            $locked->forceFill(['continuation' => $continuation])->save();
        });
    }

    /** The run row is the outbox; a sweeper can re-dispatch any unconsumed terminal handoff. */
    public static function dispatchIfReady(ApiToolRun $run): void
    {
        if ($run->terminal() && $run->resumed_at === null && ! data_get($run->continuation, 'defer_dispatch', false)
            && in_array(data_get($run->continuation, 'type'), ['agent', 'chat'], true)) {
            ResumeApiTool::dispatch($run->id, $run->tenant_id)->afterCommit();
        }
    }

    public function recoverReady(string $tenantId, int $limit = 100): int
    {
        $dispatched = 0;
        ApiToolRun::query()->forTenant($tenantId)->where('mode', 'production')->whereNull('resumed_at')
            ->whereIn('status', ['completed', 'partial', 'failed', 'cancelled', 'timed_out'])
            ->whereNotNull('continuation')->chunkById(100, function ($runs) use (&$dispatched, $limit): bool {
                foreach ($runs as $run) {
                    if (in_array(data_get($run->continuation, 'type'), ['agent', 'chat'], true)) {
                        if (data_get($run->continuation, 'defer_dispatch', false)) {
                            $agent = AgentRun::query()->forTenant($run->tenant_id)->find(data_get($run->continuation, 'agent_run_id'));
                            if ($agent === null || $agent->isTerminal() || ! data_get($agent->result_json, 'wave_dispatching', false)
                                || data_get($agent->result_json, 'pending_api_tools.'.$run->id) !== true) { continue; }
                            // The handler's existing parent lock prevents this recovery
                            // delivery from interrupting an actively collecting wave.
                            app(AgentRunDispatcher::class)->enqueue($agent);
                            if (++$dispatched >= max(1, $limit)) { return false; }
                            continue;
                        }
                        self::dispatchIfReady($run);
                        if (++$dispatched >= max(1, $limit)) { return false; }
                    }
                }
                return true;
            });
        return $dispatched;
    }

    public function resume(ApiToolRun $tool): void
    {
        $tool->refresh();
        if (! $tool->terminal() || $tool->resumed_at !== null || data_get($tool->continuation, 'defer_dispatch', false)) { return; }
        if (data_get($tool->continuation, 'type') === 'agent') { $this->resumeAgent($tool); }
        elseif (data_get($tool->continuation, 'type') === 'chat') { $this->resumeChat($tool); }
    }

    private function resumeAgent(ApiToolRun $tool): void
    {
        $agent = DB::transaction(function () use ($tool): ?AgentRun {
            $locked = ApiToolRun::query()->forTenant($tool->tenant_id)->whereKey($tool->id)->lockForUpdate()->firstOrFail();
            if ($locked->resumed_at !== null || data_get($locked->continuation, 'defer_dispatch', false)) { return null; }
            $handoff = (array) $locked->continuation;
            $agent = AgentRun::query()->forTenant($tool->tenant_id)->whereKey($handoff['agent_run_id'])->lockForUpdate()->first();
            if ($agent === null || $agent->isTerminal()) {
                $locked->forceFill(['resumed_at' => now()])->save();
                return null;
            }
            AgentExecutionContext::fromArray($locked->context)->assertMatches($agent);
            $checkpoint = (array) $agent->result_json;
            $wave = isset($handoff['wave_id']);
            // A queue dispatch can fail after the merge committed. Re-drive only the dispatch.
            if (data_get($checkpoint, 'api_tool_resumptions.'.$tool->id) === true) {
                if (! $wave || ($agent->status === AgentRun::STATUS_QUEUED && ! ($checkpoint['wave_dispatching'] ?? false)
                    && (array) ($checkpoint['pending_api_tools'] ?? []) === [])) { return $agent; }
                $locked->forceFill(['resumed_at' => now()])->save();
                return null;
            }
            $pending = $wave
                ? (($checkpoint['pending_api_tools'][$tool->id] ?? false) === true
                    && ($agent->status === AgentRun::STATUS_WAITING_API_TOOL
                        || ($agent->status === AgentRun::STATUS_RUNNING && ($checkpoint['wave_dispatching'] ?? false))))
                : ($agent->status === AgentRun::STATUS_WAITING_API_TOOL && data_get($checkpoint, 'pending_api_tool.run_id') === $tool->id);
            if (! $pending) {
                throw new \DomainException('api_tool_continuation_not_pending');
            }
            $definition = $handoff['tool'];
            $toolDefinition = new AgentToolDefinition(
                name: $definition['name'], displayName: $definition['display_name'], description: '', kind: $definition['kind'],
                inputSchema: [], readOnly: true, idempotent: true, physicalMinimum: 0, physicalLikely: 0, physicalMaximum: 0,
                executorReference: $definition['executor_reference'] ?? null, metadata: $definition['metadata'] ?? [],
            );
            $body = $this->body($locked);
            $masker = app(WidgetPiiMasker::class);
            $safe = $masker->maskArray($body) ?? [];
            $evidence = new AgentEvidenceEnvelope($masker);
            $evidence->import((array) ($checkpoint['evidence'] ?? []));
            $execution = $agent->toolExecutions()->whereKey($handoff['execution_id'])->firstOrFail();
            $executionMeta = (array) $execution->result_meta_json;
            $success = ! isset($body['error']);
            $execution->forceFill([
                'status' => $success ? 'completed' : 'failed',
                'result_meta_json' => [...$executionMeta, 'result' => $safe, 'complete' => $locked->status === 'completed', 'api_tool_run_id' => $locked->id],
                'error_code' => $success ? null : ($locked->error_code ?? 'api_tool_failed'),
                'physical_request_count' => $locked->physical_requests,
                'latency_ms' => (int) min(2147483647, max(0, now()->getTimestamp() - $locked->created_at->getTimestamp()) * 1000),
                'completed_at' => now(),
            ])->save();
            $evidence->addToolResult($toolDefinition, $handoff['arguments'], $body, $execution->id);
            if ($locked->status !== 'completed') { $evidence->addWarning($locked->error_code ?? 'api_tool_incomplete', $toolDefinition->name); }
            $completed = (array) ($checkpoint['completed_actions'] ?? []);
            $completed[] = [
                'id' => $handoff['action_id'], 'execution_id' => $execution->id, 'tool' => $toolDefinition->name,
                'kind' => $toolDefinition->kind, 'purpose' => $handoff['purpose'], 'status' => $success ? 'completed' : 'failed', 'result' => $safe,
                ...isset($executionMeta['arguments_hash']) ? ['arguments_hash' => $executionMeta['arguments_hash']] : [],
            ];
            $results = (array) ($checkpoint['action_results'] ?? []);
            $results[$handoff['action_id']] = $safe;
            if ($wave) { unset($checkpoint['pending_api_tools'][$tool->id]); }
            else { unset($checkpoint['pending_api_tool']); }
            $checkpoint['api_tool_resumptions'][$tool->id] = true;
            $ready = ! $wave || (! ($checkpoint['wave_dispatching'] ?? false)
                && (array) ($checkpoint['pending_api_tools'] ?? []) === []);
            $finishedWaves = $wave && $ready ? $this->completeWaves($checkpoint, $handoff['wave_id']) : [];
            $agent->forceFill(['status' => $ready ? AgentRun::STATUS_QUEUED : AgentRun::STATUS_WAITING_API_TOOL, 'result_json' => array_merge($checkpoint, [
                'evidence' => $evidence->jsonSerialize(), 'completed_actions' => $completed, 'action_results' => $results,
            ])])->save();
            (new AgentBudgetTracker($agent))->recordResult((int) $locked->physical_requests, strlen(json_encode($safe, JSON_THROW_ON_ERROR)), $success);
            app(AgentEventPublisher::class)->publish($agent, $success ? 'tool.completed' : 'tool.failed', $success ? 'tool.completed' : 'tool.failed',
                ['tool' => $toolDefinition->displayName], ['tool' => $toolDefinition->name, 'action_id' => $handoff['action_id'], 'tool_run_id' => $tool->id]);
            foreach ($finishedWaves as $finishedWave) { app(AgentEventPublisher::class)->publish($agent, 'wave.completed', data: $finishedWave); }
            if (! $ready) {
                $locked->forceFill(['resumed_at' => now()])->save();
                return null;
            }
            return $agent;
        });
        if ($agent !== null) {
            app(AgentRunDispatcher::class)->enqueue($agent);
            $tool->forceFill(['resumed_at' => now()])->save();
        }
    }

    /** @return list<array<string,mixed>> */
    private function completeWaves(array &$checkpoint, ?string $waveId = null): array
    {
        $completed = [];
        foreach ((array) ($checkpoint['waves'] ?? []) as $id => $wave) {
            if (($waveId !== null && $id !== $waveId) || ! is_array($wave)
                || ! in_array($wave['status'] ?? null, ['running', 'waiting'], true)) { continue; }
            $checkpoint['waves'][$id]['status'] = 'completed';
            $completed[] = ['id' => $id, ...$checkpoint['waves'][$id]];
        }
        return $completed;
    }

    private function resumeChat(ApiToolRun $tool): void
    {
        $handoff = (array) $tool->continuation;
        $user = User::findOrFail($handoff['user_id']);
        $conversation = Conversation::query()->forTenant($tool->tenant_id)->whereKey($handoff['conversation_id'])->where('user_id', $user->id)->firstOrFail();
        $context = AgentExecutionContext::fromArray($tool->context);
        if ($context->actorType !== 'user' || $context->actorId !== (string) $user->id || ($conversation->project_key ?? '') !== ($context->projectKey ?? '')) { throw new \DomainException('api_tool_continuation_scope_mismatch'); }
        try { app(ToolAccess::class)->assertActor($context); }
        catch (\DomainException) {
            // Revocation is a terminal handoff outcome, not a retryable model error.
            $tool->forceFill(['resumed_at' => now(), 'continuation' => array_merge($handoff, ['resume_error' => 'api_tool_authorization_revoked'])])->save();
            return;
        }
        if (is_string(data_get($handoff, 'child_handoff.run_id'))) {
            $child = ApiToolRun::query()->forTenant($tool->tenant_id)->findOrFail($handoff['child_handoff']['run_id']);
            self::dispatchIfReady($child);
            $tool->forceFill(['resumed_at' => now()])->save();
            return;
        }
        $response = $handoff['response'] ?? null;
        if (! is_array($response)) {
            $answer = app(McpToolCallingService::class)->resumeApiTool($tool, $user);
            $tool->refresh();
            if (is_string(data_get($tool->continuation, 'child_handoff.run_id'))) {
                $tool->forceFill(['resumed_at' => now()])->save();
                return;
            }
            $response = ['content' => $answer->content, 'provider' => $answer->provider, 'model' => $answer->model, 'tool_calls' => $answer->toolCalls, 'finish_reason' => $answer->finishReason];
            $tool->refresh();
            $tool->forceFill(['continuation' => array_merge((array) $tool->continuation, ['response' => $response])])->save();
        }
        DB::transaction(function () use ($tool, $conversation, $response): void {
            $locked = ApiToolRun::query()->forTenant($tool->tenant_id)->whereKey($tool->id)->lockForUpdate()->firstOrFail();
            if ($locked->resumed_at !== null) { return; }
            $masker = app(WidgetPiiMasker::class);
            Message::create([
                'tenant_id' => $tool->tenant_id, 'conversation_id' => $conversation->id, 'role' => 'assistant',
                'content' => $masker->maskString($response['content']),
                'metadata' => ['api_tool_continuation_id' => $tool->id, 'provider' => $response['provider'], 'model' => $response['model'],
                    'tool_calls' => $masker->maskArray($response['tool_calls']) ?? [], 'tool_calls_count' => count($response['tool_calls']), 'finish_reason' => $response['finish_reason']],
            ]);
            $conversation->touch();
            $locked->forceFill(['resumed_at' => now()])->save();
        });
    }

    public function body(ApiToolRun $run): array
    {
        if (in_array($run->status, ['completed', 'partial'], true)) { return (array) $run->outputs; }
        return ['error' => $run->error_code ?? 'api_tool_'.$run->status];
    }
}
