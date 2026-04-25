<?php

namespace App\Http\Controllers\Api\Admin;

use App\Services\Admin\AdminMetricsService;
use App\Services\Admin\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard metrics endpoints.
 *
 * Three JSON reads — overview / series / health — backed by
 * {@see AdminMetricsService} and {@see HealthCheckService}. The two
 * data-heavy endpoints are wrapped in a 30-second `Cache::remember`
 * keyed by `(project,days)` so repeated polls from the SPA don't
 * re-aggregate the chat_logs table on every request.
 *
 * Health is NOT cached — the dashboard treats it as a freshness probe
 * and each of its checks is cheap (a DB ping, a Schema::hasTable, a
 * config read).
 *
 * RBAC is enforced at the route layer via Spatie's `role:admin|super-admin`
 * middleware. This controller only sees already-authorized requests.
 */
class DashboardMetricsController extends Controller
{
    public function __construct(
        private readonly AdminMetricsService $metrics,
        private readonly HealthCheckService $health,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $project = $this->project($request);
        $days = $this->days($request);

        $payload = Cache::remember(
            $this->cacheKey('overview', $project, $days),
            30,
            fn () => $this->metrics->kpiOverview($project, $days),
        );

        return response()->json([
            'project' => $project,
            'days' => $days,
            'overview' => $payload,
        ]);
    }

    public function series(Request $request): JsonResponse
    {
        $project = $this->project($request);
        $days = $this->days($request);

        $payload = Cache::remember(
            $this->cacheKey('series', $project, $days),
            30,
            fn () => [
                'chat_volume' => $this->metrics->chatVolume($project, $days),
                'token_burn' => $this->metrics->tokenBurn($project, $days),
                'rating_distribution' => $this->metrics->ratingDistribution($project, $days),
                // Copilot #1 fix: top_projects + activity_feed now
                // respect the same (project, days) scope as the rest
                // of the series payload. Without this, the cache keyed
                // by (project, days) could return identical feeds
                // for logically different queries.
                'top_projects' => $this->metrics->topProjects(10, $project, $days),
                'activity_feed' => $this->metrics->activityFeed(20, $project),
            ],
        );

        return response()->json([
            'project' => $project,
            'days' => $days,
            ...$payload,
        ]);
    }

    public function health(): JsonResponse
    {
        return response()->json($this->health->run());
    }

    private function project(Request $request): ?string
    {
        $raw = $request->query('project');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return trim($raw);
    }

    private function days(Request $request): int
    {
        $raw = (int) $request->query('days', 7);

        // Clamp so a rogue `days=9999` can't force a multi-year aggregate.
        return max(1, min(90, $raw));
    }

    private function cacheKey(string $kind, ?string $project, int $days): string
    {
        return sprintf('admin.metrics.%s.%s.%d', $kind, $project ?? 'all', $days);
    }
}
