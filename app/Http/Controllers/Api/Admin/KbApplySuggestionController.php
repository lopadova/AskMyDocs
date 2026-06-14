<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbDocAnalysis;
use App\Services\Kb\Analysis\SuggestionApplier;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.11/P8 — HTTP surface (R44) of the apply engine:
 *   POST /api/admin/kb/analyses/{id}/apply { type, target }
 * A manual apply is an explicit human action (actor = admin:<user id>), so it
 * may touch a human-curated doc — unlike auto-apply. Delegates to
 * {@see SuggestionApplier}; tenant-scoped (R30), RBAC-gated (R32 matrix entry).
 */
final class KbApplySuggestionController extends Controller
{
    public function __construct(
        private readonly SuggestionApplier $applier,
        private readonly TenantContext $tenants,
    ) {}

    public function apply(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['cross_reference', 'impacted'])],
            'target' => ['required', 'string', 'max:255'],
        ]);

        $analysis = KbDocAnalysis::query()
            ->forTenant($this->tenants->current())
            ->find($id);
        if ($analysis === null) {
            throw new NotFoundHttpException('Analysis not found.');
        }

        $result = $this->applier->apply(
            $analysis,
            $validated['type'],
            $validated['target'],
            'admin:'.(string) ($request->user()?->id ?? 'unknown'),
        );

        return response()->json(['data' => $result]);
    }
}
