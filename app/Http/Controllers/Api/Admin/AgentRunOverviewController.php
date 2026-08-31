<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AgentPlannerShadowReport;
use App\Models\AgentRun;
use App\Models\AgentToolExecution;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/** Tenant-scoped, PII-free operations summary for the connector agent loop. */
final class AgentRunOverviewController extends Controller
{
    public function __invoke(TenantContext $tenants): JsonResponse
    {
        $tenantId = $tenants->current();
        $since = now()->subDay();
        $runs = AgentRun::query()
            ->forTenant($tenantId)
            ->where('created_at', '>=', $since)
            ->get([
                'id', 'run_id', 'project_key', 'channel', 'locale', 'status',
                'counters_json', 'error_code', 'started_at', 'completed_at', 'created_at',
            ]);
        $runIds = $runs->pluck('id');
        $executions = $runIds->isEmpty()
            ? collect()
            : AgentToolExecution::query()
                ->whereIn('agent_run_id', $runIds)
                ->get([
                    'agent_run_id', 'status', 'tool_name', 'tool_kind', 'error_code',
                    'physical_request_count', 'latency_ms', 'result_meta_json',
                ]);
        $mcpExecutions = $executions->where('tool_kind', 'mcp');
        $cacheObserved = $mcpExecutions->filter(
            fn (AgentToolExecution $execution): bool => is_bool(data_get($execution->result_meta_json, 'stats.mcp.negotiation_cache_hit')),
        );
        $terminalDurations = $runs
            ->filter(fn (AgentRun $run): bool => $run->started_at !== null && $run->completed_at !== null)
            ->map(fn (AgentRun $run): int => (int) $run->started_at->diffInMilliseconds($run->completed_at));
        $successful = $runs->whereIn('status', [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL])->count();
        $plannerReports = AgentPlannerShadowReport::query()
            ->forTenant($tenantId)
            ->where('created_at', '>=', $since)
            ->get();
        $shadowReports = $plannerReports->where('mode', 'shadow');
        $invalidShadowReports = $shadowReports->filter(
            fn (AgentPlannerShadowReport $report): bool => (int) data_get($report->comparison_json, 'validation_corrections', 0) > 0
                || in_array($report->error_code, [
                    'unknown_tool', 'write_tool_forbidden', 'arguments_schema_invalid',
                    'reference_path_invalid', 'reference_dependency_missing', 'reference_value_missing',
                    'reference_source_invalid', 'speculative_reference_path', 'premature_insufficient',
                ], true),
        );

        $recent = AgentRun::query()
            ->forTenant($tenantId)
            ->withCount('toolExecutions')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(static fn (AgentRun $run): array => [
                'run_id' => $run->run_id,
                'project_key' => $run->project_key,
                'channel' => $run->channel,
                'locale' => $run->locale,
                'status' => $run->status,
                'error_code' => $run->error_code,
                'logical_calls' => (int) data_get($run->counters_json, 'logical_calls', 0),
                'physical_calls' => (int) data_get($run->counters_json, 'physical_calls', 0),
                'tool_executions' => (int) $run->tool_executions_count,
                'created_at' => $run->created_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['data' => [
            'window' => ['hours' => 24, 'since' => $since->toIso8601String()],
            'metrics' => [
                'runs' => $runs->count(),
                'successful_runs' => $successful,
                'failed_runs' => $runs->where('status', AgentRun::STATUS_FAILED)->count(),
                'cancelled_runs' => $runs->where('status', AgentRun::STATUS_CANCELLED)->count(),
                'success_rate' => $runs->isEmpty() ? null : round(($successful / $runs->count()) * 100, 1),
                'logical_calls' => $runs->sum(fn (AgentRun $run): int => (int) data_get($run->counters_json, 'logical_calls', 0)),
                'physical_requests' => $executions->sum('physical_request_count'),
                'tool_executions' => $executions->count(),
                'tool_failures' => $executions->where('status', 'failed')->count(),
                'average_duration_ms' => $terminalDurations->isEmpty() ? null : (int) round($terminalDurations->average()),
            ],
            'status_counts' => $runs->countBy('status')->all(),
            'planner_shadow' => [
                'reports' => $plannerReports->count(),
                'agreement_rate' => $shadowReports->isEmpty()
                    ? null
                    : round(($shadowReports->where('status', 'agreement')->count() / $shadowReports->count()) * 100, 1),
                'agreements' => $shadowReports->where('status', 'agreement')->count(),
                'disagreements' => $shadowReports->where('status', 'disagreement')->count(),
                'invalid_plan_rate' => $shadowReports->isEmpty()
                    ? null
                    : round(($invalidShadowReports->count() / $shadowReports->count()) * 100, 1),
                'errors' => $plannerReports->where('status', 'error')->count(),
                'fallbacks' => $plannerReports->where('fallback_used', true)->count(),
                'validation_corrections' => $plannerReports->sum(
                    fn (AgentPlannerShadowReport $report): int => (int) data_get($report->comparison_json, 'validation_corrections', 0),
                ),
                'premature_insufficient_avoided' => $plannerReports->filter(
                    fn (AgentPlannerShadowReport $report): bool => (bool) data_get($report->comparison_json, 'premature_insufficient_avoided', false),
                )->count(),
                'average_candidates' => $plannerReports->isEmpty() ? null : round($plannerReports->avg(
                    fn (AgentPlannerShadowReport $report): int => count($report->candidate_tools_json ?? []),
                ), 1),
                'average_router_latency_ms' => $this->averagePresent($plannerReports->pluck('router_latency_ms')->all()),
                'average_planner_latency_ms' => $this->averagePresent($plannerReports->pluck('planner_latency_ms')->all()),
                'average_tokens' => $this->averagePresent($plannerReports->map(
                    fn (AgentPlannerShadowReport $report): ?int => $report->prompt_tokens === null && $report->completion_tokens === null
                        ? null
                        : (int) $report->prompt_tokens + (int) $report->completion_tokens,
                )->all()),
            ],
            'mcp_transport' => [
                'executions' => $mcpExecutions->count(),
                'physical_requests' => $mcpExecutions->sum('physical_request_count'),
                'negotiation_cache_hit_rate' => $cacheObserved->isEmpty()
                    ? null
                    : round(($cacheObserved->filter(
                        fn (AgentToolExecution $execution): bool => data_get(
                            $execution->result_meta_json,
                            'stats.mcp.negotiation_cache_hit',
                        ) === true,
                    )->count() / $cacheObserved->count()) * 100, 1),
                'average_oauth_refresh_ms' => $this->executionStatAverage($mcpExecutions, 'oauth_refresh_ms'),
                'average_endpoint_guard_dns_ms' => $this->executionStatAverage($mcpExecutions, 'endpoint_guard_dns_ms'),
                'average_discovery_ms' => $this->executionStatAverage($mcpExecutions, 'discovery_ms'),
                'average_tool_call_ms' => $this->executionStatAverage($mcpExecutions, 'tool_call_ms'),
                'average_decode_ms' => $this->executionStatAverage($mcpExecutions, 'decode_ms'),
                'recoveries' => $mcpExecutions
                    ->map(fn (AgentToolExecution $execution): mixed => data_get(
                        $execution->result_meta_json,
                        'stats.mcp.recovery',
                    ))
                    ->filter(static fn (mixed $value): bool => is_string($value))
                    ->countBy()
                    ->all(),
                'error_codes' => $mcpExecutions
                    ->where('status', 'failed')
                    ->pluck('error_code')
                    ->filter(static fn (mixed $value): bool => is_string($value))
                    ->countBy()
                    ->all(),
            ],
            'policy' => $this->policy(),
            'recent_runs' => $recent,
        ]]);
    }

    /** @return array<string,int> */
    private function policy(): array
    {
        return collect([
            'iterations', 'logical_soft', 'logical_hard', 'physical_hard',
            'consecutive_errors', 'duplicate_calls', 'interactive_time_seconds',
            'bulk_time_seconds', 'evidence_bytes', 'confirmation_logical_extension_max',
            'confirmation_physical_extension_max',
        ])->mapWithKeys(static fn (string $key): array => [
            $key => (int) config('agent.limits.'.$key),
        ])->all();
    }

    /** @param list<mixed> $values */
    private function averagePresent(array $values): ?int
    {
        $present = array_values(array_filter($values, static fn (mixed $value): bool => is_int($value) || is_float($value)));

        return $present === [] ? null : (int) round(array_sum($present) / count($present));
    }

    private function executionStatAverage(Collection $executions, string $key): ?int
    {
        return $this->averagePresent($executions
            ->map(fn (AgentToolExecution $execution): mixed => data_get(
                $execution->result_meta_json,
                'stats.mcp.'.$key,
            ))
            ->all());
    }
}
