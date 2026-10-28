<?php

declare(strict_types=1);

namespace App\Agent\Waves;

use App\Agent\AgentEventPublisher;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\AgentRunControl;
use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Capabilities\AgentCapabilitySnapshot;
use App\Agent\Debug\RunDiagnostics;
use App\Agent\Evidence\AgentEvidenceAccess;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Planning\AgentAmbiguousSelectionGuard;
use App\Agent\Planning\AgentArgumentResolver;
use App\Agent\Planning\AgentPlanningCoordinator;
use App\Agent\Tools\AgentToolActionResult;
use App\Agent\Tools\AgentToolFailure;
use App\ApiTools\ToolContinuation;
use App\Models\AgentRun;
use App\Services\Chat\Reasoning\ResearchFailure;
use App\Services\Widget\WidgetPiiMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One planner owns every research wave; workers return observations, never plans. */
final class AgentWaveCoordinator
{
    public function __construct(
        private AgentPlanningCoordinator $planner,
        private AgentWaveExecutor $executor,
        private AgentEvidenceFactory $factory,
        private AgentEventPublisher $events,
        private AgentRunControl $control,
        private RunDiagnostics $diagnostics,
        private WidgetPiiMasker $masker,
    ) {}

    public function run(AgentRun $run, AgentExecutionContext $context, ?string $turnContext, array $tools, AgentCapabilitySnapshot $snapshot): AgentLoopOutcome
    {
        $evidence = $this->factory->empty();
        $evidence->import((array) data_get($run->result_json, 'evidence', []));
        $completed = (array) data_get($run->result_json, 'completed_actions', []);
        $results = (array) data_get($run->result_json, 'action_results', []);
        $budget = new AgentBudgetTracker($run);
        if (data_get($run->result_json, 'wave_dispatching', false)
            && $this->recoverOpenWave($run, $evidence, $completed, $results, $budget)) {
            return new AgentLoopOutcome('waiting_api_tool', $evidence, $completed, 'api_tool_pending');
        }
        $question = (string) data_get($run->input_json, 'question');
        if (data_get($run->result_json, 'wave_mcp_interactions', []) !== []) {
            return $this->awaitInteractions($run, $evidence, $completed);
        }

        $confirmation = data_get($run->result_json, 'wave_budget_confirmation');
        if (is_string($confirmation)) return $this->awaitBudget($run, $evidence, $completed, $confirmation);

        while (true) {
            $this->control->ensureActive($run);
            $safe = $this->factory->empty();
            $safe->import(app(AgentEvidenceAccess::class)->current($run, $context, $evidence->jsonSerialize()));
            $evidence = $safe;
            // Historical action bodies are operational history only. Rebuild dependency
            // values exclusively from currently authorized observations.
            $results = [];
            foreach ($completed as &$entry) {
                $documentIds = array_column($entry['result']['documents'] ?? [], 'document_id');
                $entry['result'] = [];
                if (($entry['kind'] ?? null) === 'knowledge' && ($entry['status'] ?? null) === 'completed') {
                    $entry['result'] = $results[$entry['id']] = ['documents' => array_values(array_filter($evidence->documents(),
                        fn ($document) => in_array($document['document_id'] ?? null, $documentIds, true)))];
                }
                foreach ($evidence->apiTools() as $observation) {
                    if (isset($entry['execution_id']) && ($observation['execution_id'] ?? null) === $entry['execution_id']) {
                        $results[$entry['id']] = $observation['result'];
                        $entry['result'] = $observation['result'];
                    }
                }
            }
            unset($entry);
            $selection = data_get($run->input_json, 'selection.record');
            if (is_array($selection)) {
                $selected = array_intersect_key($selection, array_flip(['id', 'code', 'trackingCode', 'sku', 'orderNumber', 'customerCode', 'productCode']));
                $results['current_selection'] = $results['selected_row'] = $selected;
            }
            $this->checkpoint($run, $evidence, $completed, $results);
            $iteration = $budget->beginIteration(max((float) config('agent.planner.time_reserve_seconds', 30),
                (int) ($budget->snapshot()['last_planning_ms'] ?? 0) / 1000));
            if (! $iteration->allowed()) return new AgentLoopOutcome('partial', $evidence, $completed, $iteration->reason);

            $this->events->publish($run, $completed === [] ? 'plan.created' : 'plan.updated', $completed === [] ? 'plan.created' : 'plan.updated');
            $start = microtime(true);
            try {
                $plan = $this->diagnostics->measure($run, 'planner.decide', fn () => $this->planner->decide(
                    $run, (int) $budget->snapshot()['iterations'], $question, $context, $tools, $snapshot, $evidence,
                    $this->history($completed), $results, json_encode([
                        'previous_context' => $turnContext,
                        'question_understanding' => data_get($run->result_json, 'question_understanding'),
                        'previous_waves' => data_get($run->result_json, 'waves', []),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), ['wait_reason' => 'provider_response']);
            } catch (\Throwable $exception) {
                if (! $evidence->hasEvidence()) throw $exception;
                $evidence->addWarning('planner_recovery', 'agent_planner');
                $this->checkpoint($run, $evidence, $completed, $results);
                return new AgentLoopOutcome('partial', $evidence, $completed, 'planner_recovery');
            }
            $budget->recordPlanningDuration((int) round((microtime(true) - $start) * 1000));
            $run->forceFill(['plan_json' => $plan->jsonSerialize()])->save();
            $this->events->publish($run, 'plan.ready', data: ['decision' => $plan->decision, 'actions' => count($plan->actions)]);
            if (! $plan->shouldCallTools()) return new AgentLoopOutcome($plan->decision, $evidence, $completed);

            $waveId = (string) Str::uuid();
            $ready = $deferred = [];
            $confirmation = null;
            $finished = array_column($completed, null, 'id');
            foreach ($plan->actions as $action) {
                // Dependencies from THIS plan belong to a later common evaluation.
                $satisfied = array_keys(array_filter($finished, fn ($entry) => ($entry['status'] ?? null) === 'completed'));
                if (array_diff($action->dependsOn, [...$satisfied, 'current_selection', 'selected_row']) !== []) {
                    $deferred[] = $action->id;
                    continue;
                }
                $tool = $tools[$action->tool];
                try {
                    $arguments = app(AgentArgumentResolver::class)->resolve($action->arguments, $results);
                } catch (\Throwable) {
                    $this->skipped($run, $action, $tool, 'dependency_resolution_failed', $completed);
                    continue;
                }
                $signature = $this->signature($arguments);
                $duplicate = false;
                foreach ($completed as $previous) {
                    if (($previous['tool'] ?? null) === $tool->name && ($previous['arguments_hash'] ?? null) === $signature
                        && (($previous['status'] ?? null) === 'completed' || ($previous['failure']['retryable'] ?? null) === false)) {
                        $duplicate = true;
                        break;
                    }
                }
                if ($duplicate) {
                    $this->skipped($run, $action, $tool, 'duplicate_call_avoided', $completed);
                    continue;
                }
                if (app(AgentAmbiguousSelectionGuard::class)->blocks($evidence->apiTools(), $arguments,
                    is_array(data_get($run->input_json, 'selection')) ? data_get($run->input_json, 'selection') : null)) {
                    $this->skipped($run, $action, $tool, 'ambiguous_selection_required', $completed);
                    continue;
                }
                $decision = $budget->reserve($tool, $arguments, $tool->physicalLikely);
                if (! $decision->allowed()) {
                    if ($decision->requiresConfirmation()) $confirmation = $decision->reason;
                    // Finish the other ready observations; the next common decision sees
                    // which action hit the shared budget rather than losing its siblings.
                    $this->skipped($run, $action, $tool, $decision->reason ?? 'budget_limit', $completed);
                    continue;
                }
                $ready[$action->id] = ['action' => $action, 'tool' => $tool, 'arguments' => $arguments];
            }
            foreach ($plan->rejectedActions as $action) {
                $this->skipped($run, $action, $tools[$action->tool], 'input_pattern_branch_rejected', $completed);
            }
            if ($ready === []) {
                $this->checkpoint($run, $evidence, $completed, $results);
                if ($confirmation !== null) return $this->awaitBudget($run, $evidence, $completed, $confirmation);
                return new AgentLoopOutcome('partial', $evidence, $completed, 'no_runnable_actions');
            }

            // Divide the physical allowance BEFORE dispatch: isolated workers cannot
            // each spend the parent's full quota or overwrite its counters.
            $state = $budget->snapshot();
            $remaining = max(0, (int) $state['physical_hard'] - (int) $state['physical_calls']);
            $remoteCount = count(array_filter($ready, fn ($item) => ! in_array($item['tool']->kind, ['knowledge', 'catalog'], true)));
            $remoteIndex = 0;
            $tasks = [];
            foreach ($ready as $id => &$item) {
                $remote = ! in_array($item['tool']->kind, ['knowledge', 'catalog'], true);
                $quota = $remote ? intdiv($remaining, $remoteCount) + ($remoteIndex++ < $remaining % $remoteCount ? 1 : 0) : 0;
                if ($remote && $quota === 0) {
                    $confirmation = 'physical_hard_limit';
                    $this->skipped($run, $item['action'], $item['tool'], $confirmation, $completed);
                    unset($ready[$id]);
                    continue;
                }
                $item['quota'] = $quota;
                $item['execution'] = $run->toolExecutions()->create([
                    'logical_index' => (int) $run->toolExecutions()->max('logical_index') + 1,
                    'tool_name' => $item['tool']->name, 'tool_kind' => $item['tool']->kind,
                    'api_route_id' => $item['tool']->kind === 'api' && is_int($item['tool']->executorReference) ? $item['tool']->executorReference : null,
                    'status' => 'running', 'depends_on_json' => $item['action']->dependsOn,
                    'result_meta_json' => ['action_id' => $id, 'wave_id' => $waveId, 'physical_quota' => $quota, 'arguments_hash' => $this->signature($item['arguments'])],
                    'arguments_json' => $this->masker->maskArray($item['arguments']) ?? [], 'started_at' => now(),
                ]);
                $payload = ['run_id' => $run->id, 'context' => $context->jsonSerialize(), 'tool' => $item['tool'],
                    'arguments' => $item['arguments'], 'physical_quota' => $quota,
                    'wave_id' => $waveId, 'action_id' => $id, 'execution_id' => $item['execution']->id, 'purpose' => $item['action']->purpose];
                $tasks[$id] = static fn () => app(AgentWaveTask::class)->execute($payload);
                $this->events->publish($run, 'tool.started', 'tool.started', ['tool' => $item['tool']->displayName],
                    ['tool' => $item['tool']->name, 'action_id' => $id, 'wave_id' => $waveId]);
            }
            unset($item);
            $this->checkpoint($run, $evidence, $completed, $results);
            $wave = ['id' => $waveId, 'status' => 'running', 'actions' => array_keys($ready), 'deferred_actions' => $deferred];
            $this->saveWave($run, $wave);
            DB::transaction(function () use ($run): void {
                $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
                $this->control->ensureActive($locked);
                $locked->forceFill(['result_json' => [...(array) $locked->result_json, 'wave_dispatching' => true]])->save();
            });
            $this->events->publish($run, 'wave.started', data: $wave);
            $started = microtime(true);
            $waveTimeout = max(0.1, min((float) config('agent.waves.timeout_seconds', 45),
                app(\App\Agent\AgentRunDeadline::class)->remaining($run) - (float) config('agent.synthesis.time_reserve_seconds', 60)));
            $observations = $this->diagnostics->measure($run, 'wave.execute', fn () => $this->executor->run($tasks,
                diagnostics: $this->diagnostics->observer($run), timeoutSeconds: $waveTimeout), ['operation' => 'parallel_wave', 'concurrency' => (int) config('agent.waves.concurrency', 10)]);
            $this->control->ensureActive($run);
            $pending = $interactions = [];
            $registered = [];
            foreach (array_keys((array) data_get($run->fresh()->result_json, 'pending_api_tools', [])) as $toolRunId) {
                $toolRun = \App\ApiTools\Models\ApiToolRun::query()->forTenant($run->tenant_id)->find($toolRunId);
                if ($toolRun !== null) $registered[data_get($toolRun->continuation, 'execution_id')] = $toolRunId;
            }
            foreach ($ready as $id => $item) {
                $observation = $observations[$id] ?? new ResearchFailure('wave_result_missing');
                if (isset($registered[$item['execution']->id])) {
                    $observation = new AgentToolActionResult(['_api_tool_pending' => $registered[$item['execution']->id]], 0, false);
                }
                if (! $observation instanceof AgentToolActionResult) {
                    // A dead process may have sent its request already: charge its
                    // reserved allowance conservatively instead of permitting overspend.
                    $code = $observation instanceof ResearchFailure && str_contains($observation->reason, 'timeout') ? 'wave_timeout' : 'wave_task_failed';
                    $observation = new AgentToolActionResult(['error' => $code], $item['quota'], false, $code);
                }
                if (is_string($observation->body['_api_tool_pending'] ?? null)) {
                    $pending[] = [$observation->body['_api_tool_pending'], $item];
                    continue;
                }
                DB::transaction(function () use ($run, $item, $id, $observation, $started, $waveId, $budget,
                    &$evidence, &$completed, &$results, &$interactions, &$confirmation): void {
                $execution = $item['execution'];
                $tool = $item['tool'];
                $body = $this->masker->maskArray($observation->body) ?? [];
                $execution->forceFill([
                    'status' => $observation->successful() ? 'completed' : 'failed',
                    'result_meta_json' => [...(array) $execution->result_meta_json, 'result' => $body, 'complete' => $observation->complete, 'stop_reason' => $observation->stopReason, 'stats' => $observation->stats],
                    'error_code' => $observation->successful() ? null : ($observation->stopReason ?? 'tool_error'),
                    'physical_request_count' => $observation->physicalRequests,
                    'latency_ms' => (int) ($observation->stats['wave_latency_ms'] ?? round((microtime(true) - $started) * 1000)), 'completed_at' => now(),
                ])->save();
                $before = $evidence->byteSize();
                if ($tool->kind === 'knowledge') {
                    $evidence->import(['documents' => $body['documents'] ?? []]);
                    $budget->recordKnowledgeSearchResult($evidence->byteSize() > $before);
                } else {
                    $evidence->addToolResult($tool, $item['arguments'], $observation->body, $execution->id);
                }
                $budget->recordResult($observation->physicalRequests, max(0, $evidence->byteSize() - $before), $observation->successful());
                if (! $observation->complete) $evidence->addWarning($observation->stopReason ?? 'incomplete', $tool->name);
                $completed[] = ['id' => $id, 'execution_id' => $execution->id, 'tool' => $tool->name, 'kind' => $tool->kind,
                    'purpose' => $item['action']->purpose, 'status' => $observation->successful() ? 'completed' : 'failed',
                    'result' => $body, 'arguments_hash' => $this->signature($item['arguments']),
                    ...$observation->successful() ? [] : ['failure' => AgentToolFailure::summarize($observation->body, $tool->inputSchema)]];
                $results[$id] = $body;
                $this->events->publish($run, $observation->successful() ? 'tool.completed' : 'tool.failed',
                    $observation->successful() ? 'tool.completed' : 'tool.failed', ['tool' => $tool->displayName],
                    ['tool' => $tool->name, 'action_id' => $id, 'wave_id' => $waveId, 'physical_requests' => $observation->physicalRequests]);
                if ($observation->stopReason === 'physical_hard_limit') $confirmation = 'physical_hard_limit';
                if (in_array($observation->stopReason, ['mcp_confirmation_required', 'mcp_input_required', 'mcp_task_accepted'], true)) {
                    $interactions[] = ['reason' => $observation->stopReason, 'interaction' => $observation->stats['mcp'] ?? []];
                }
                $this->saveBarriers($run, $interactions, $confirmation);
                $this->checkpoint($run, $evidence, $completed, $results);
                });
            }
            $this->checkpoint($run, $evidence, $completed, $results);
            $wave['status'] = $pending === [] ? 'completed' : 'waiting';
            $this->saveWave($run, $wave);
            // Save interaction/budget barriers even when composed API tools are still
            // outstanding; the next delivery must expose them before planning again.
            $this->saveBarriers($run, $interactions, $confirmation);
            if ($pending !== []) {
                // Attachment and barrier closure commit together. Ready callbacks are
                // dispatched after commit, so partial registration cannot strand a run.
                DB::transaction(function () use ($pending, $run, $waveId): void {
                    foreach ($pending as [$toolRunId, $item]) {
                        app(ToolContinuation::class)->attachAgent($toolRunId, $run, [
                            'wave_id' => $waveId, 'defer_dispatch' => false,
                            'action_id' => $item['action']->id, 'purpose' => $item['action']->purpose,
                            'execution_id' => $item['execution']->id, 'arguments' => $item['arguments'],
                            'tool' => ['name' => $item['tool']->name, 'display_name' => $item['tool']->displayName, 'kind' => $item['tool']->kind,
                                'executor_reference' => $item['tool']->executorReference, 'metadata' => $item['tool']->metadata],
                        ]);
                    }
                    app(ToolContinuation::class)->finishAgentWave($run);
                });
                return new AgentLoopOutcome('waiting_api_tool', $evidence, $completed, 'api_tool_pending');
            }
            // No async child remains. Close registration without enqueueing this
            // currently executing parent, then assess the complete wave synchronously.
            $run->refresh();
            $run->forceFill(['result_json' => [...(array) $run->result_json, 'wave_dispatching' => false]])->save();
            $this->events->publish($run, 'wave.completed', data: $wave);
            if ($interactions !== []) return $this->awaitInteractions($run, $evidence, $completed);
            if ($confirmation !== null) return $this->awaitBudget($run, $evidence, $completed, $confirmation);
            // This is the only back-edge. No worker or dependent action can
            // advance until all wave observations have crossed the common barrier.
        }
    }

    /** Redelivery after an interrupted parent never blindly repeats in-flight calls. */
    private function recoverOpenWave(AgentRun $run, AgentEvidenceEnvelope $evidence, array &$completed, array $results, AgentBudgetTracker $budget): bool
    {
        $pending = [];
        $owned = [];
        foreach (array_keys((array) data_get($run->result_json, 'pending_api_tools', [])) as $id) {
            $tool = \App\ApiTools\Models\ApiToolRun::query()->forTenant($run->tenant_id)->find($id);
            if ($tool !== null) {
                $pending[$id] = [...(array) $tool->continuation, 'defer_dispatch' => false];
                $owned[] = data_get($tool->continuation, 'execution_id');
            }
        }
        DB::transaction(function () use ($run, $owned, $budget, $results, &$completed, &$evidence): void {
        foreach ($run->toolExecutions()->where('status', 'running')->get() as $execution) {
            if (in_array($execution->id, $owned, true)) continue;
            $meta = (array) $execution->result_meta_json;
            $execution->forceFill(['status' => 'failed', 'error_code' => 'wave_interrupted', 'completed_at' => now()])->save();
            $completed[] = ['id' => $meta['action_id'] ?? 'interrupted_'.$execution->id, 'execution_id' => $execution->id,
                'tool' => $execution->tool_name, 'kind' => $execution->tool_kind, 'status' => 'failed', 'error_code' => 'wave_interrupted',
                'arguments_hash' => $meta['arguments_hash'] ?? null,
                'failure' => ['code' => 'wave_interrupted', 'retryable' => false]];
            $budget->recordResult((int) ($meta['physical_quota'] ?? 0), 0, false);
            $evidence->addWarning('wave_interrupted', $execution->tool_name);
        }
        $this->checkpoint($run, $evidence, $completed, $results);
        });
        if ($pending !== []) {
            DB::transaction(function () use ($pending, $run): void {
                foreach ($pending as $id => $handoff) app(ToolContinuation::class)->attachAgent($id, $run, $handoff);
                app(ToolContinuation::class)->finishAgentWave($run);
            });
            return true;
        }
        $run->refresh();
        $run->forceFill(['status' => AgentRun::STATUS_RUNNING,
            'result_json' => [...(array) $run->result_json, 'wave_dispatching' => false]])->save();
        return false;
    }

    private function saveBarriers(AgentRun $run, array $interactions, ?string $confirmation): void
    {
        DB::transaction(function () use ($run, $interactions, $confirmation): void {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            $this->control->ensureActive($locked);
            $state = (array) $locked->result_json;
            if ($interactions !== []) $state['wave_mcp_interactions'] = $interactions;
            if ($confirmation !== null) $state['wave_budget_confirmation'] = $confirmation;
            $run->forceFill(['result_json' => $state])->save();
        });
    }

    private function awaitInteractions(AgentRun $run, AgentEvidenceEnvelope $evidence, array $completed): AgentLoopOutcome
    {
        $interactions = (array) data_get($run->result_json, 'wave_mcp_interactions', []);
        $first = $interactions[0];
        $this->control->awaitMcpInteraction($run, $first['reason'], [...$first['interaction'], 'interactions' => $interactions]);
        return new AgentLoopOutcome(match ($first['reason']) {
            'mcp_confirmation_required' => 'awaiting_mcp_confirmation', 'mcp_input_required' => 'awaiting_mcp_input', default => 'waiting_mcp_task',
        }, $evidence, $completed, $first['reason']);
    }

    private function awaitBudget(AgentRun $run, AgentEvidenceEnvelope $evidence, array $completed, string $reason): AgentLoopOutcome
    {
        $state = (array) $run->result_json;
        unset($state['wave_budget_confirmation']);
        $run->forceFill(['result_json' => $state])->save();
        $this->control->awaitConfirmation($run, $reason === 'logical_hard_limit'
            ? ['logical' => min(10, (int) config('agent.limits.confirmation_logical_extension_max', 25)), 'physical' => 1]
            : ['logical' => 0, 'physical' => min(25, (int) config('agent.limits.confirmation_physical_extension_max', 100))]);
        return new AgentLoopOutcome('awaiting_confirmation', $evidence, $completed, $reason);
    }

    private function checkpoint(AgentRun $run, AgentEvidenceEnvelope $evidence, array $completed, array $results): void
    {
        DB::transaction(function () use ($run, $evidence, $completed, $results): void {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            $this->control->ensureActive($locked);
            $run->forceFill(['result_json' => [...(array) $locked->result_json, 'phase' => 'collection', 'retrieval_completed' => true,
                'evidence' => $evidence->jsonSerialize(), 'completed_actions' => $this->masker->maskArray($completed) ?? [],
                'action_results' => $this->masker->maskArray($results) ?? []]])->save();
        });
    }

    private function saveWave(AgentRun $run, array $wave): void
    {
        DB::transaction(function () use ($run, $wave): void {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            $this->control->ensureActive($locked);
            $waves = (array) data_get($locked->result_json, 'waves', []);
            $waves[$wave['id']] = $wave;
            $run->forceFill(['result_json' => [...(array) $locked->result_json, 'waves' => array_slice($waves, -20, null, true)]])->save();
        });
    }

    private function skipped(AgentRun $run, $action, $tool, string $code, array &$completed): void
    {
        $execution = $run->toolExecutions()->create(['logical_index' => (int) $run->toolExecutions()->max('logical_index') + 1,
            'tool_name' => $tool->name, 'tool_kind' => $tool->kind, 'status' => 'skipped', 'depends_on_json' => $action->dependsOn,
            'arguments_json' => $this->masker->maskArray($action->arguments) ?? [], 'error_code' => $code, 'physical_request_count' => 0, 'completed_at' => now()]);
        $completed[] = ['id' => $action->id, 'execution_id' => $execution->id, 'tool' => $tool->name, 'purpose' => $action->purpose,
            'status' => 'skipped', 'error_code' => $code];
    }

    private function history(array $completed): array
    {
        return array_map(fn ($entry) => array_intersect_key($entry, array_flip(['id', 'tool', 'status', 'purpose', 'failure', 'error_code'])), array_slice($completed, -40));
    }

    private function signature(array $arguments): string
    {
        $normalize = function ($value) use (&$normalize) {
            if (! is_array($value)) return $value;
            if (! array_is_list($value)) ksort($value);
            return array_map($normalize, $value);
        };
        return hash('sha256', json_encode($normalize($arguments), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
