<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Admin\ProvenanceInsightsService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.32 / ADR 0028 phase 1 — HTTP read surface (R44) for ingest provenance.
 *
 * The sibling of the `kb:provenance` command and the MCP `KbProvenanceTool`,
 * over the SAME core {@see ProvenanceInsightsService}. Thin by design: it
 * validates input, delegates, and shapes the response.
 *
 * R32 — gated by the admin KB route group, and represented in the
 * authorization matrix by `/api/admin/kb/provenance`.
 */
final class KbProvenanceController extends Controller
{
    public function index(
        Request $request,
        ProvenanceInsightsService $service,
        TenantContext $tenants,
    ): JsonResponse {
        $validated = $request->validate([
            'project_key' => ['sometimes', 'nullable', 'string', 'max:190'],
            'per_project' => ['sometimes', 'boolean'],
            // Bounded so a caller cannot ask for the whole project table in
            // one response (R30-adjacent resource limit).
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $projectKey = $validated['project_key'] ?? null;
        $projectKey = is_string($projectKey) && $projectKey !== '' ? $projectKey : null;

        $payload = [
            'tenant_id' => $tenants->current(),
            'summary' => $service->summary($projectKey),
        ];

        if ($request->boolean('per_project')) {
            $payload['per_project'] = $service->byProject((int) ($validated['limit'] ?? 50));
        }

        return response()->json($payload);
    }
}
