<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Kb\AutoWiki\ConceptSynthesizer;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.11/P3 — HTTP surface (R44) of concept-page synthesis:
 *   POST /api/admin/kb/concepts/synthesize { project_key, limit? }
 * Delegates to {@see ConceptSynthesizer}; tenant-scoped (R30), RBAC-gated by the
 * admin KB route group (R32 matrix entry).
 */
final class KbConceptSynthesisController extends Controller
{
    public function __construct(
        private readonly ConceptSynthesizer $synthesizer,
        private readonly TenantContext $tenants,
    ) {}

    public function synthesize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['required', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $this->synthesizer->synthesize(
            $this->tenants->current(),
            $validated['project_key'],
            isset($validated['limit']) ? (int) $validated['limit'] : null,
        );

        return response()->json(['data' => $result]);
    }
}
