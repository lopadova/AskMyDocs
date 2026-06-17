<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Engagement\EngagementMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.15/W4 — the per-user "your KB" dashboard (R44 HTTP surface).
 *
 * `/api/me/dashboard` — the authenticated user's own contributions, rank,
 * authored docs, questions asked, active days, and personal review queue.
 * auth:sanctum + tenant.authorize; tenant-scoped (R30) via the service.
 */
final class UserDashboardController extends Controller
{
    public function __construct(private readonly EngagementMetricsService $metrics)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $windowDays = max(1, min(90, (int) $request->integer('days', 30)));
        $userId = (int) $request->user()->getKey();

        return response()->json([
            'window_days' => $windowDays,
            'dashboard' => $this->metrics->userDashboard($userId, $windowDays),
        ]);
    }
}
