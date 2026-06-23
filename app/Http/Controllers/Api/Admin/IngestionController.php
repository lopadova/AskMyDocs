<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Admin\IngestionObservabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.21 (Ciclo 2) — read-only ingestion / sync observability for the admin
 * "Ingestion & Sync" screen.
 *
 * Endpoints (behind `auth:sanctum` + `tenant.authorize` + `can:manageConnectors`
 * — same allow-set as the connectors surface, super-admin only):
 *   GET /api/admin/ingestion/queue                     → queue depths
 *   GET /api/admin/connectors/{installationId}/sync-runs → per-account history
 *
 * Both delegate to {@see IngestionObservabilityService} (R44 one core; R30
 * tenant scoping enforced inside the service).
 */
final class IngestionController extends Controller
{
    public function __construct(private readonly IngestionObservabilityService $service) {}

    public function queue(): JsonResponse
    {
        return response()->json(['data' => $this->service->queueDepths()]);
    }

    public function syncRuns(Request $request, int $installationId): JsonResponse
    {
        $limit = (int) $request->integer('limit', 20);

        return response()->json([
            'data' => $this->service->syncRunsForInstallation($installationId, $limit),
        ]);
    }
}
