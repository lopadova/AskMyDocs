<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AgentRun;
use App\Models\AgentToolExecution;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

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
                ->get(['agent_run_id', 'status', 'tool_kind', 'physical_request_count', 'latency_ms']);
        $terminalDurations = $runs
            ->filter(fn (AgentRun $run): bool => $run->started_at !== null && $run->completed_at !== null)
            ->map(fn (AgentRun $run): int => (int) $run->started_at->diffInMilliseconds($run->completed_at));
        $successful = $runs->whereIn('status', [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL])->count();

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
}
