<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbEngagementSnapshot;
use App\Services\Engagement\EngagementMetricsService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.15/W1 — admin engagement analytics (R44 HTTP surface).
 *
 * Thin controller over {@see EngagementMetricsService}. Gated by
 * `role:admin|super-admin` at the route (R32 — see AdminAuthorizationMatrixTest
 * row for `/api/admin/engagement/summary`). All reads tenant-scoped (R30).
 */
class EngagementController extends Controller
{
    public function __construct(
        private readonly EngagementMetricsService $metrics,
        private readonly TenantContext $tenants,
    ) {
    }

    /**
     * Latest engagement snapshot (the daily-computed row). Falls back to a live
     * compute when no snapshot exists yet (fresh install) OR when the snapshot's
     * metrics column is null/empty (partial-compute), so the dashboard is never
     * blank — and surfaces `source` so the caller knows which it got. Mirrors
     * {@see \App\Mcp\Tools\KbEngagementSummaryTool}.
     */
    public function summary(Request $request): JsonResponse
    {
        $snapshot = KbEngagementSnapshot::query()
            ->forTenant($this->tenants->current())
            ->latestSnapshot()
            ->first();

        $snapshotMetrics = $snapshot?->metrics;
        $usingSnapshot = $snapshotMetrics !== null && $snapshotMetrics !== [];

        if ($usingSnapshot) {
            return response()->json([
                'source' => 'snapshot',
                'snapshot_date' => $snapshot->snapshot_date->toDateString(),
                'computed_at' => $snapshot->computed_at?->toIso8601String(),
                'metrics' => $snapshotMetrics,
            ]);
        }

        $windowDays = $this->resolveWindow($request);

        return response()->json([
            'source' => 'live',
            'snapshot_date' => null,
            'computed_at' => null,
            'metrics' => $this->metrics->snapshotMetrics($windowDays),
        ]);
    }

    /**
     * Contributor leaderboard for the window (live — always fresh).
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $windowDays = $this->resolveWindow($request);
        $limit = max(1, min(50, (int) $request->integer('limit', 10)));

        return response()->json([
            'window_days' => $windowDays,
            'limit' => $limit,
            'leaderboard' => $this->metrics->leaderboard($windowDays, $limit),
        ]);
    }

    /**
     * Engagement trend series for the admin charts (from the snapshot history).
     */
    public function series(Request $request): JsonResponse
    {
        $points = max(1, min(60, (int) $request->integer('points', 8)));

        return response()->json([
            'points' => $points,
            'series' => $this->metrics->trendSeries($points),
        ]);
    }

    private function resolveWindow(Request $request): int
    {
        return max(1, min(90, (int) $request->integer('days', 7)));
    }
}
