<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Planning\AgentArgumentResolver;
use App\Agent\Planning\AgentAmbiguousSelectionGuard;
use App\Agent\Planning\AgentPlan;
use App\Agent\Planning\AgentPlannedAction;
use App\Agent\Planning\AgentPlanner;
use App\Agent\Tools\AgentServerToolRunner;
use App\Agent\Tools\AgentToolActionResult;
use App\Agent\Tools\AgentToolDefinition;
use App\Agent\Tools\AgentToolRegistry;
use App\Mcp\Debug\McpActivityDebugPayload;
use App\Models\AgentRun;
use App\Models\AgentToolExecution;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Widget\WidgetPiiMasker;
use Throwable;

/** Bounded plan → act → observe → re-plan data-retrieval loop. */
final readonly class AgentLoop
{
    public function __construct(
        private AgentPlanner $planner,
        private AgentToolRegistry $registry,
        private AgentArgumentResolver $arguments,
        private AgentAmbiguousSelectionGuard $ambiguousSelection,
        private AgentServerToolRunner $serverTools,
        private ChatRetrievalService $retrieval,
        private AgentEvidenceFactory $evidenceFactory,
        private AgentEventPublisher $events,
        private AgentRunControl $control,
        private WidgetPiiMasker $masker,
        private AgentRetrievalFiltersFactory $retrievalFilters,
        private McpActivityDebugPayload $mcpDebug,
    ) {}

    public function run(
        AgentRun $run,
        AgentExecutionContext $context,
        ?string $turnContext = null,
    ): AgentLoopOutcome
    {
        $question = trim((string) data_get($run->input_json, 'question', ''));
        if ($question === '') {
            throw new \InvalidArgumentException('agent_question_required');
        }

        $budget = new AgentBudgetTracker($run);
        $filters = $this->retrievalFilters->forRun($run, $context);
        [$evidence, $completed, $results, $retrieved] = $this->restore($run);
        $selectedRecord = data_get($run->input_json, 'selection.record');
        if (is_array($selectedRecord)) {
            // A row selected in a prior turn is a first-class dependency source.
            // The planner may refer to it as {"$from":"current_selection","path":"id"}.
            $results['current_selection'] = $selectedRecord;
            $results['selected_row'] = $selectedRecord;
        }
        $user = $run->user;
        $tools = $this->registry->forContext($context, $user instanceof User ? $user : null);

        if (! $retrieved) {
            $this->control->ensureActive($run);
            $this->events->publish($run, 'retrieval.started', 'retrieval.started');
            try {
                $search = $this->retrieval->retrieve($question, $context->projectKey, $filters);
                $documents = $this->evidenceFactory->fromSearchResult($search);
                $evidence->import($documents->jsonSerialize());
                $budget->recordResult(0, $documents->byteSize(), true);
                $this->events->publish(
                    $run,
                    'retrieval.completed',
                    'retrieval.completed',
                    ['count' => count($evidence->documents())],
                    ['sources' => count($evidence->documents())],
                );
            } catch (Throwable) {
                $evidence->addWarning('knowledge_retrieval_failed', 'search_knowledge_base');
                $this->events->publish($run, 'retrieval.failed', 'tool.failed', ['tool' => 'Knowledge base']);
            }
            $retrieved = true;
            $this->checkpoint($run, $evidence, $completed, $results, $retrieved);
        }

        while (true) {
            $this->control->ensureActive($run);
            $iteration = $budget->beginIteration();
            if (! $iteration->allowed()) {
                return $this->outcome('partial', $evidence, $completed, $iteration->reason);
            }

            $this->events->publish(
                $run,
                $completed === [] ? 'plan.created' : 'plan.updated',
                $completed === [] ? 'plan.created' : 'plan.updated',
            );
            $plan = $this->planner->decide(
                $question,
                $context,
                $tools,
                $evidence,
                $this->plannerHistory($completed),
                $turnContext,
            );
            $run->forceFill(['plan_json' => $plan->jsonSerialize()])->save();
            $this->events->publish(
                $run,
                'plan.ready',
                null,
                data: ['decision' => $plan->decision, 'actions' => count($plan->actions)],
                progress: $this->progress($budget, $plan),
            );

            if (! $plan->shouldCallTools()) {
                return $this->outcome($plan->decision, $evidence, $completed);
            }

            foreach ($plan->actions as $action) {
                $this->control->ensureActive($run);
                $tool = $tools[$action->tool];
                try {
                    $resolved = $this->arguments->resolve($action->arguments, $results);
                } catch (Throwable $exception) {
                    $code = 'dependency_resolution_failed';
                    $evidence->addWarning($code, $tool->name);
                    $completed[] = [
                        'id' => $action->id,
                        'tool' => $tool->name,
                        'purpose' => $action->purpose,
                        'status' => 'skipped',
                        'error_code' => $code,
                    ];
                    $this->recordSkippedExecution($run, $action, $tool, $code);
                    $this->events->publish(
                        $run,
                        'tool.failed',
                        'tool.failed',
                        ['tool' => $tool->displayName],
                        ['tool' => $tool->name, 'action_id' => $action->id, 'error_code' => $code],
                        $this->progress($budget, $plan),
                    );
                    $this->checkpoint($run, $evidence, $completed, $results, $retrieved);
                    continue;
                }
                $selection = data_get($run->input_json, 'selection');
                if ($this->ambiguousSelection->blocks(
                    $evidence->apiTools(),
                    $resolved,
                    is_array($selection) ? $selection : null,
                )) {
                    $evidence->addWarning('ambiguous_selection_required', $tool->name);
                    $this->checkpoint($run, $evidence, $completed, $results, $retrieved);

                    return $this->outcome('answer', $evidence, $completed, 'ambiguous_selection_required');
                }
                $decision = $budget->reserve($tool, $resolved, $tool->physicalLikely);
                if ($decision->requiresConfirmation()) {
                    $this->checkpoint($run, $evidence, $completed, $results, $retrieved);
                    $this->control->awaitConfirmation($run, $this->extensionFor($decision->reason));

                    return $this->outcome('awaiting_confirmation', $evidence, $completed, $decision->reason);
                }
                if (! $decision->allowed()) {
                    $evidence->addWarning((string) $decision->reason, $tool->name);

                    return $this->outcome('partial', $evidence, $completed, $decision->reason);
                }
                if ($decision->autoExtended) {
                    $this->events->publish($run, 'budget.extended', 'budget.extended');
                }

                $execution = $this->startExecution($run, $action, $tool, $resolved, $budget, $plan);
                $started = microtime(true);
                try {
                    $result = $this->executeAction(
                        $run,
                        $context,
                        $action,
                        $tool,
                        $resolved,
                        $budget,
                        $evidence,
                        $plan,
                        $filters,
                    );
                    $this->finishExecution($execution, $result, $started);
                    $results[$action->id] = $result->body;
                    $completed[] = [
                        'id' => $action->id,
                        'tool' => $tool->name,
                        'purpose' => $action->purpose,
                        'status' => $result->successful() ? 'completed' : 'failed',
                        'result' => $this->masker->maskArray($result->body) ?? [],
                    ];
                    if ($tool->kind !== 'knowledge') {
                        $evidence->addToolResult($tool, $resolved, $result->body, $execution->id);
                    }
                    if (! $result->complete && $result->stopReason !== null) {
                        $evidence->addWarning($result->stopReason, $tool->name);
                    }

                    $eventData = [
                        'tool' => $tool->name,
                        'action_id' => $action->id,
                        'physical_requests' => $result->physicalRequests,
                        'complete' => $result->complete,
                        'stop_reason' => $result->stopReason,
                    ];
                    $debug = $this->mcpDebug->capture(
                        $tool,
                        $resolved,
                        $result->body,
                        (int) round((microtime(true) - $started) * 1000),
                        $result->successful() ? 'ok' : 'error',
                    );
                    if ($debug !== null) {
                        $eventData['mcp_debug'] = $debug;
                    }

                    $this->events->publish(
                        $run,
                        $result->successful() ? 'tool.completed' : 'tool.failed',
                        $result->successful() ? 'tool.completed' : 'tool.failed',
                        ['tool' => $tool->displayName],
                        $eventData,
                        $this->progress($budget, $plan),
                    );
                    $this->checkpoint($run, $evidence, $completed, $results, $retrieved);

                    if ($result->stopReason === 'physical_hard_limit') {
                        $this->control->awaitConfirmation($run, $this->extensionFor($result->stopReason));

                        return $this->outcome('awaiting_confirmation', $evidence, $completed, $result->stopReason);
                    }
                } catch (AgentRunCancelledException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $this->failExecution($execution, $exception, $started);
                    $budget->recordResult(0, 0, false);
                    $evidence->addWarning($this->errorCode($exception), $tool->name);
                    $completed[] = [
                        'id' => $action->id,
                        'tool' => $tool->name,
                        'purpose' => $action->purpose,
                        'status' => 'failed',
                        'error_code' => $this->errorCode($exception),
                    ];
                    $eventData = [
                        'tool' => $tool->name,
                        'action_id' => $action->id,
                        'error_code' => $this->errorCode($exception),
                    ];
                    $debug = $this->mcpDebug->capture(
                        $tool,
                        $resolved,
                        null,
                        (int) round((microtime(true) - $started) * 1000),
                        'error',
                        $exception,
                    );
                    if ($debug !== null) {
                        $eventData['mcp_debug'] = $debug;
                    }

                    $this->events->publish(
                        $run,
                        'tool.failed',
                        'tool.failed',
                        ['tool' => $tool->displayName],
                        $eventData,
                        $this->progress($budget, $plan),
                    );
                    $this->checkpoint($run, $evidence, $completed, $results, $retrieved);
                }
            }
        }
    }

    private function executeAction(
        AgentRun $run,
        AgentExecutionContext $context,
        AgentPlannedAction $action,
        AgentToolDefinition $tool,
        array $arguments,
        AgentBudgetTracker $budget,
        AgentEvidenceEnvelope $evidence,
        AgentPlan $plan,
        \App\Services\Kb\Retrieval\RetrievalFilters $filters,
    ): AgentToolActionResult {
        if ($tool->kind === 'knowledge') {
            $query = trim((string) ($arguments['query'] ?? ''));
            if ($query === '') {
                throw new \InvalidArgumentException('knowledge_query_required');
            }
            $search = $this->retrieval->retrieve($query, $context->projectKey, $filters);
            $found = $this->evidenceFactory->fromSearchResult($search);
            $evidence->import($found->jsonSerialize());
            $body = ['documents' => $found->documents()];
            $budget->recordResult(0, strlen((string) json_encode($body)), true);

            return new AgentToolActionResult($body, 0);
        }

        return $this->serverTools->execute(
            $tool,
            $arguments,
            $context,
            $run,
            $budget,
            function (array $event) use ($run, $tool, $action, $budget, $plan): void {
                $completed = max(0, (int) ($event['completed'] ?? 0));
                $estimated = max($completed, (int) ($event['estimated_total'] ?? $tool->physicalLikely));
                $this->events->publish(
                    $run,
                    'tool.progress',
                    'tool.progress',
                    ['completed' => $completed, 'estimated' => $estimated],
                    ['tool' => $tool->name, 'action_id' => $action->id] + $event,
                    $this->progress($budget, $plan),
                );
                $this->control->ensureActive($run);
            },
        );
    }

    private function startExecution(
        AgentRun $run,
        AgentPlannedAction $action,
        AgentToolDefinition $tool,
        array $arguments,
        AgentBudgetTracker $budget,
        AgentPlan $plan,
    ): AgentToolExecution {
        $index = (int) $run->toolExecutions()->max('logical_index') + 1;
        $execution = $run->toolExecutions()->create([
            'logical_index' => $index,
            'tool_name' => $tool->name,
            'tool_kind' => $tool->kind,
            'api_route_id' => $tool->kind === 'api' ? (int) $tool->executorReference : null,
            'status' => 'running',
            'depends_on_json' => $action->dependsOn,
            'arguments_json' => $this->masker->maskArray($arguments) ?? [],
            'started_at' => now(),
        ]);
        $this->events->publish(
            $run,
            'tool.started',
            'tool.started',
            ['tool' => $tool->displayName],
            ['tool' => $tool->name, 'action_id' => $action->id, 'purpose' => $action->purpose],
            $this->progress($budget, $plan),
        );

        return $execution;
    }

    private function finishExecution(AgentToolExecution $execution, AgentToolActionResult $result, float $started): void
    {
        $execution->forceFill([
            'status' => $result->successful() ? 'completed' : 'failed',
            'result_meta_json' => [
                'result' => $this->masker->maskArray($result->body) ?? [],
                'complete' => $result->complete,
                'stop_reason' => $result->stopReason,
                'stats' => $result->stats,
            ],
            'error_code' => $result->successful() ? null : ($result->stopReason ?? 'tool_error'),
            'physical_request_count' => $result->physicalRequests,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'completed_at' => now(),
        ])->save();
    }

    private function failExecution(AgentToolExecution $execution, Throwable $exception, float $started): void
    {
        $execution->forceFill([
            'status' => 'failed',
            'error_code' => $this->errorCode($exception),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'completed_at' => now(),
        ])->save();
    }

    private function recordSkippedExecution(
        AgentRun $run,
        AgentPlannedAction $action,
        AgentToolDefinition $tool,
        string $errorCode,
    ): void {
        $run->toolExecutions()->create([
            'logical_index' => (int) $run->toolExecutions()->max('logical_index') + 1,
            'tool_name' => $tool->name,
            'tool_kind' => $tool->kind,
            'api_route_id' => $tool->kind === 'api' ? (int) $tool->executorReference : null,
            'status' => 'skipped',
            'depends_on_json' => $action->dependsOn,
            'arguments_json' => $this->masker->maskArray($action->arguments) ?? [],
            'error_code' => $errorCode,
            'physical_request_count' => 0,
            'completed_at' => now(),
        ]);
    }

    /** @return array{AgentEvidenceEnvelope,list<array<string,mixed>>,array<string,array<string,mixed>>,bool} */
    private function restore(AgentRun $run): array
    {
        $checkpoint = is_array($run->result_json) ? $run->result_json : [];
        $evidence = $this->evidenceFactory->empty();
        if (is_array($checkpoint['evidence'] ?? null)) {
            $evidence->import($checkpoint['evidence']);
        }

        return [
            $evidence,
            is_array($checkpoint['completed_actions'] ?? null) ? array_values($checkpoint['completed_actions']) : [],
            is_array($checkpoint['action_results'] ?? null) ? $checkpoint['action_results'] : [],
            (bool) ($checkpoint['retrieval_completed'] ?? false),
        ];
    }

    /** @param list<array<string,mixed>> $completed @param array<string,array<string,mixed>> $results */
    private function checkpoint(
        AgentRun $run,
        AgentEvidenceEnvelope $evidence,
        array $completed,
        array $results,
        bool $retrieved,
    ): void {
        $run->forceFill(['result_json' => [
            'phase' => 'collection',
            'retrieval_completed' => $retrieved,
            'evidence' => $evidence->jsonSerialize(),
            'completed_actions' => $this->masker->maskArray($completed) ?? [],
            'action_results' => $this->masker->maskArray($results) ?? [],
        ]])->save();
    }

    /** @param list<array<string,mixed>> $completed */
    private function plannerHistory(array $completed): array
    {
        return array_map(static fn (array $action): array => [
            'id' => $action['id'] ?? null,
            'tool' => $action['tool'] ?? null,
            'status' => $action['status'] ?? null,
            'result_summary' => is_array($action['result'] ?? null)
                ? array_slice($action['result'], 0, 10, true)
                : null,
            'error_code' => $action['error_code'] ?? null,
        ], array_slice($completed, -20));
    }

    private function progress(AgentBudgetTracker $budget, AgentPlan $plan): AgentProgress
    {
        $state = $budget->snapshot();
        $logical = (int) ($state['logical_calls'] ?? 0);
        $physical = (int) ($state['physical_calls'] ?? 0);

        return new AgentProgress(
            logicalCompleted: $logical,
            logicalMinimum: $logical,
            logicalLikely: max($logical, $logical + $plan->estimate->logicalLikely),
            logicalMaximum: max($logical, $logical + $plan->estimate->logicalMaximum),
            physicalCompleted: $physical,
            physicalMinimum: $physical,
            physicalLikely: max($physical, $physical + $plan->estimate->physicalLikely),
            physicalMaximum: max($physical, $physical + $plan->estimate->physicalMaximum),
        );
    }

    /** @return array<string,int> */
    private function extensionFor(?string $reason): array
    {
        return $reason === 'logical_hard_limit'
            ? ['logical' => min(10, (int) config('agent.limits.confirmation_logical_extension_max', 25)), 'physical' => 1]
            : ['logical' => 0, 'physical' => min(25, (int) config('agent.limits.confirmation_physical_extension_max', 100))];
    }

    /** @param list<array<string,mixed>> $completed */
    private function outcome(string $decision, AgentEvidenceEnvelope $evidence, array $completed, ?string $reason = null): AgentLoopOutcome
    {
        return new AgentLoopOutcome($decision, $evidence, $completed, $reason);
    }

    private function errorCode(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'scope') || str_contains($message, 'unauthor')) {
            return 'unauthorized';
        }
        if (str_contains($message, 'rate') || str_contains($message, '429')) {
            return 'rate_limited';
        }
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }

        return 'unavailable';
    }
}
