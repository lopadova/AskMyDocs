<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbGamificationInsight;
use App\Services\Engagement\GamificationInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.18/W4 — admin AI gamification insights: the project / tenant knowledge-health
 * narrative (R44 HTTP surface, R32 matrix rows). Reads are admin|super-admin
 * (route group); {@see regenerate()} is super-admin only (it can fan out LLM
 * calls across the tenant). Everything is tenant-scoped via the service (R30).
 *
 * R14/R43: a disabled feature or a not-yet-computed scope returns 200 with
 * `available:false`, never a 500. A bad `scope` returns 422.
 */
class GamificationInsightsController extends Controller
{
    public function show(Request $request, GamificationInsightsService $insights): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'in:project,tenant'],
            'id' => ['nullable', 'string', 'max:120'],
        ]);

        $scope = $validated['scope'] ?? KbGamificationInsight::SCOPE_TENANT;

        if ($scope === KbGamificationInsight::SCOPE_PROJECT && trim((string) ($validated['id'] ?? '')) === '') {
            return response()->json([
                'message' => 'A project id is required when scope=project.',
                'errors' => ['id' => ['The id field is required for the project scope.']],
            ], 422);
        }

        $scopeId = $scope === KbGamificationInsight::SCOPE_PROJECT ? (string) $validated['id'] : '';
        $insight = $insights->forScope($scope, $scopeId);

        return response()->json([
            'available' => $insight !== null,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'insight' => $insight,
        ]);
    }

    public function regenerate(Request $request, GamificationInsightsService $insights): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'string', 'max:32'],
        ]);

        $result = $insights->recomputeForTenant($validated['period'] ?? null);

        return response()->json([
            'regenerated' => $insights->enabled(),
            'result' => $result,
        ], 202);
    }
}
