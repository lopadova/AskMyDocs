<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Admin\KbTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Reader-side KB tree, for the Browse KB page.
 *
 * Contract: GET /api/kb/tree?project=&mode=
 *   - 200: { tree, counts, generated_at } — byte-identical envelope to
 *          {@see \App\Http\Controllers\Api\Admin\KbTreeController::index},
 *          so the SPA reuses the KbTreeResponse/KbTreeNode types verbatim.
 *
 * SAME core (R44): this is a second HTTP surface over the existing
 * {@see KbTreeService} capability — like the reader
 * {@see KbDocumentPreviewController} sits beside the admin document
 * read — not a new one. No Artisan command and no MCP tool, because the
 * capability already has both through the admin surface and the MCP
 * retrieval tools.
 *
 * Soft deletes (R2): `false` is passed unconditionally, so a reader can
 * never see a deleted document. `with_trashed` is not DECLARED — and
 * `validate()` does not reject undeclared keys, so a client that sends
 * it gets a 200 with the parameter ignored rather than a 422. That is
 * deliberate: rejecting a harmless extra query parameter is harsher than
 * ignoring it, and the guarantee lives in the call below, not in the
 * validator. `KbTreeReaderTest` pins the ignoring.
 *
 * Isolation (R30/R33): the service queries `KnowledgeDocument` with
 * `forTenant(current())` and WITHOUT `withoutGlobalScopes()`, so the
 * model's AccessScopeScope applies — the same SQL-level enforcement the
 * RAG hot path relies on, rather than a policy this surface would skip.
 *
 * Visibility posture, stated plainly because it surprises people: with
 * `config('kb.project_isolation.enabled') === false` (the default) and
 * the `viewer` role holding `kb.read.any` (RbacSeeder),
 * `AccessScopeScope::apply()` short-circuits on `canReadAllProjects()`
 * and a viewer sees EVERY non-trashed document in the tenant. That is
 * the pre-existing retrieval posture — identical to what chat citations
 * and /preview already expose — so this page widens nothing. Turning the
 * flag on narrows both. Both states are pinned by
 * {@see \Tests\Feature\Api\KbTreeReaderTest}.
 */
final class KbTreeReaderController extends Controller
{
    public function __construct(private readonly KbTreeService $tree)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project' => ['nullable', 'string', 'max:120'],
            'mode' => ['nullable', 'in:canonical,raw,all'],
        ]);

        $project = isset($validated['project']) && trim((string) $validated['project']) !== ''
            ? trim((string) $validated['project'])
            : null;

        $result = $this->tree->build(
            $project,
            $validated['mode'] ?? KbTreeService::MODE_ALL,
            withTrashed: false,
        );

        return response()->json([
            'tree' => $result['tree'],
            'counts' => $result['counts'],
            'generated_at' => Carbon::now()->toIso8601String(),
        ]);
    }
}
