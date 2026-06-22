<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Invite\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Admin read of the invite funnel + virality metrics (R44 HTTP surface over
 * MetricsService). Tenant-scoping happens inside the service.
 */
final class InviteMetricsController extends Controller
{
    public function __construct(private readonly MetricsService $metrics)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_id' => ['nullable', 'integer'],
            'since_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        return response()->json([
            'data' => $this->metrics->summary(
                $validated['campaign_id'] ?? null,
                $validated['since_days'] ?? null,
            ),
        ]);
    }
}
