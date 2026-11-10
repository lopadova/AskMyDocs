<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Kb\Agent\KbFacetsService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.44/W4 (ADR 0035 §D6.1) — HTTP surface of the corpus facets (R44). Thin:
 * validate → {@see KbFacetsService} → JSON.
 *
 * Authenticated, NOT role-gated: it is the data the chat filter UI needs, and
 * the figures are computed under the caller's own document ACL (the model's
 * AccessScopeScope applies to the authenticated principal — R33), so each
 * caller sees only the dimensions of documents they can already retrieve.
 * Flag-gated (R43): `KB_MCP_FACETS_ENABLED` OFF → a clean 404 `facets_disabled`.
 */
final class KbFacetsController extends Controller
{
    public function __invoke(Request $request, KbFacetsService $kbFacetsService, TenantContext $tenantContext): JsonResponse
    {
        if (! (bool) config('kb.facets.enabled', false)) {
            return response()->json([
                'code' => 'facets_disabled',
                'message' => 'Corpus facets are disabled on this deployment.',
            ], 404);
        }

        $validated = $request->validate([
            'project_key' => ['nullable', 'string', 'max:120'],
            'folder_depth' => ['nullable', 'integer', 'min:1', 'max:2'],
        ]);

        return response()->json($kbFacetsService->for(
            $tenantContext->current(),
            isset($validated['project_key']) && $validated['project_key'] !== '' ? (string) $validated['project_key'] : null,
            (int) ($validated['folder_depth'] ?? 2),
        ));
    }
}
