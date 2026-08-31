<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\AgentExecutionContext;
use App\Agent\Capabilities\AgentCapabilitySnapshot;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Agent\Tools\AgentToolDefinition;
use App\Models\AgentPlannerShadowReport;
use App\Models\AgentRun;
use App\Services\Widget\WidgetPiiMasker;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class AgentPlanningCoordinator
{
    public function __construct(
        private AgentPlanner $classic,
        private AgentCapabilityPlanner $capability,
        private AgentPlannerModeResolver $modes,
        private WidgetPiiMasker $masker,
    ) {}

    /**
     * @param  array<string,AgentToolDefinition>  $tools
     * @param  list<array<string,mixed>>  $completedActions
     */
    public function decide(
        AgentRun $run,
        int $iteration,
        string $question,
        AgentExecutionContext $context,
        array $tools,
        AgentCapabilitySnapshot $snapshot,
        AgentEvidenceEnvelope $evidence,
        array $completedActions,
        array $results,
        ?string $turnContext,
    ): AgentPlan {
        $mode = $this->modes->forContext($context);
        if ($mode === 'classic') {
            // `classic` is the rollback contract: it must remain byte-for-byte
            // equivalent to the planner path that existed before capability
            // routing was introduced. Validation, normalization and retries
            // belong exclusively to the capability planner.
            return $this->classic->decide(
                $question, $context, $tools, $evidence, $completedActions, $turnContext,
            );
        }

        $classic = null;
        if ($mode === 'shadow') {
            $classic = $this->classicAttempt(
                $question, $context, $tools, $evidence, $completedActions, $turnContext,
            );
        }

        try {
            $capability = $this->capability->decide(
                $question,
                $context,
                $tools,
                $snapshot,
                $evidence,
                $completedActions,
                $results,
                $turnContext,
            );
            $this->safeReport($run, $iteration, $mode, $snapshot, $classic, $capability);

            return $mode === 'shadow' ? $classic->plan : $capability->plan;
        } catch (Throwable $exception) {
            $fallback = $classic ?? $this->classicAttempt(
                $question, $context, $tools, $evidence, $completedActions, $turnContext,
            );
            $this->safeReport($run, $iteration, $mode, $snapshot, $fallback, null, $exception);

            return $fallback->plan;
        }
    }

    /**
     * @param  array<string,AgentToolDefinition>  $tools
     * @param  list<array<string,mixed>>  $completedActions
     */
    private function classicAttempt(
        string $question,
        AgentExecutionContext $context,
        array $tools,
        AgentEvidenceEnvelope $evidence,
        array $completedActions,
        ?string $turnContext,
    ): AgentPlannerAttempt {
        return $this->classic->decideAttempt(
            $question, $context, $tools, $evidence, $completedActions, $turnContext,
        );
    }

    private function safeReport(
        AgentRun $run,
        int $iteration,
        string $mode,
        AgentCapabilitySnapshot $snapshot,
        ?AgentPlannerAttempt $classic,
        ?AgentCapabilityPlanningResult $capability,
        ?Throwable $error = null,
    ): void {
        try {
            $this->report($run, $iteration, $mode, $snapshot, $classic, $capability, $error);
        } catch (Throwable $reportError) {
            Log::warning('Agent planner telemetry could not be persisted.', [
                'run_id' => $run->run_id,
                'iteration' => $iteration,
                'mode' => $mode,
                'exception' => $reportError::class,
            ]);
        }
    }

    private function report(
        AgentRun $run,
        int $iteration,
        string $mode,
        AgentCapabilitySnapshot $snapshot,
        ?AgentPlannerAttempt $classic,
        ?AgentCapabilityPlanningResult $capability,
        ?Throwable $error = null,
    ): void {
        $classicPlan = $classic?->plan->jsonSerialize();
        $capabilityPlan = $capability?->plan->jsonSerialize();
        $comparison = $this->comparison($classicPlan, $capabilityPlan, $capability?->corrections ?? 0);
        $status = match (true) {
            $error !== null => 'error',
            $mode === 'capability' => 'capability',
            ($comparison['decision_agreement'] ?? false) && ($comparison['tool_agreement'] ?? false) => 'agreement',
            default => 'disagreement',
        };

        AgentPlannerShadowReport::query()->updateOrCreate(
            ['agent_run_id' => $run->id, 'iteration' => $iteration],
            [
                'tenant_id' => $run->tenant_id,
                'project_key' => $run->project_key,
                'mode' => $mode,
                'status' => $status,
                'capability_hash' => $snapshot->hash,
                'capability_count' => count($snapshot->capabilities),
                'capability_bytes' => $snapshot->bytes,
                'candidate_tools_json' => $capability?->route->candidateTools,
                'route_json' => $capability === null ? null : [
                    'live_data_required' => $capability->route->liveDataRequired,
                    'entity' => $capability->route->entity,
                    'operation' => $capability->route->operation,
                    'reason_codes' => $capability->route->reasonCodes,
                ],
                'classic_plan_json' => $this->masker->maskArray($classicPlan),
                'capability_plan_json' => $this->masker->maskArray($capabilityPlan),
                'comparison_json' => $comparison,
                'router_latency_ms' => $capability?->route->latencyMs,
                'planner_latency_ms' => $capability?->plannerLatencyMs,
                'prompt_tokens' => $this->sum($capability?->route->promptTokens, $capability?->promptTokens),
                'completion_tokens' => $this->sum($capability?->route->completionTokens, $capability?->completionTokens),
                'fallback_used' => $error !== null,
                'error_code' => $error === null ? null : $this->errorCode($error),
            ],
        );
    }

    /** @return array<string,mixed> */
    private function comparison(?array $classic, ?array $capability, int $corrections): array
    {
        $classicTools = array_values(array_filter(array_column($classic['actions'] ?? [], 'tool'), 'is_string'));
        $capabilityTools = array_values(array_filter(array_column($capability['actions'] ?? [], 'tool'), 'is_string'));
        sort($classicTools);
        sort($capabilityTools);

        return [
            'decision_agreement' => ($classic['decision'] ?? null) === ($capability['decision'] ?? null),
            'tool_agreement' => $classicTools === $capabilityTools,
            'classic_tools' => $classicTools,
            'capability_tools' => $capabilityTools,
            'validation_corrections' => $corrections,
            'premature_insufficient_avoided' => ($classic['decision'] ?? null) === 'insufficient'
                && ($capability['decision'] ?? null) === 'tools',
        ];
    }

    private function sum(?int $left, ?int $right): ?int
    {
        return $left === null && $right === null ? null : (int) $left + (int) $right;
    }

    private function errorCode(Throwable $error): string
    {
        return $error instanceof AgentPlanValidationException
            ? $error->validationCode
            : 'capability_planner_failed';
    }
}
