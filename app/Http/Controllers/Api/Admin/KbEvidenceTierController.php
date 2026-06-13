<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Kb\AutoWiki\EvidenceTierService;
use App\Support\Canonical\EvidenceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.11/P1b — HTTP surface (R44) of the evidence-tier capability:
 *   GET   /api/admin/kb/evidence-tiers              → the taxonomy
 *   PATCH /api/admin/kb/documents/{id}/evidence-tier → human-set a doc's tier
 * Both delegate to {@see EvidenceTierService}; tenant-scoped (R30), RBAC-gated
 * by the admin KB route group (R32 matrix entry).
 */
final class KbEvidenceTierController extends Controller
{
    public function __construct(private readonly EvidenceTierService $service) {}

    /** The evidence-tier taxonomy (value + rank + low-confidence flag). */
    public function taxonomy(): JsonResponse
    {
        return response()->json(['data' => $this->service->taxonomy()]);
    }

    /** Human override of a document's evidence tier. */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'evidence_tier' => ['required', 'string', Rule::in(EvidenceTier::values())],
        ]);

        $doc = $this->service->findForTenant($id);
        if ($doc === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        $updated = $this->service->setTier(
            $doc,
            EvidenceTier::from($validated['evidence_tier']),
            'admin:'.(string) ($request->user()?->id ?? 'unknown'),
        );

        return response()->json(['data' => [
            'id' => (int) $updated->id,
            'evidence_tier' => $updated->evidence_tier,
        ]]);
    }
}
