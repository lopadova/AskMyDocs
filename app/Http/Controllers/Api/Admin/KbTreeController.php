<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Services\Admin\KbTreeService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Admin KB tree explorer — Phase G1.
 *
 * Thin wrapper over {@see KbTreeService}: validates the query string,
 * delegates, wraps the result in a response envelope with a
 * `generated_at` timestamp so the SPA can stamp freshness in the UI.
 *
 * RBAC is applied at the route layer (`role:admin|super-admin`).
 *
 * Scope boundary: G1 exposes browsing only. Detail payloads
 * (chunks, rendered body, frontmatter, history) live in G2 under
 * `/api/admin/kb/documents/{id}`. Source editing (G3) and the graph
 * tab (G4) also get their own endpoints — do not fold them back into
 * `tree`.
 */
class KbTreeController extends Controller
{
    public function __construct(private readonly KbTreeService $tree) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project' => ['nullable', 'string', 'max:120'],
            'mode' => ['nullable', 'in:canonical,raw,all'],
            'with_trashed' => ['nullable'],
        ]);

        $project = isset($validated['project']) && trim((string) $validated['project']) !== ''
            ? trim((string) $validated['project'])
            : null;

        $mode = $validated['mode'] ?? KbTreeService::MODE_ALL;
        $withTrashed = $request->boolean('with_trashed');

        $result = $this->tree->build($project, $mode, $withTrashed);

        return response()->json([
            'tree' => $result['tree'],
            'counts' => $result['counts'],
            'generated_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * The distinct set of project keys available in the active team, for
     * the FE project pickers. Unions THREE sources so a project shows up
     * regardless of how it came to exist (v8.9):
     *
     *   1. the `projects` registry (a project created in the admin
     *      Projects page, even before its first document);
     *   2. `knowledge_documents` (incl. soft-deleted — an admin restoring
     *      a trashed doc still needs its project);
     *   3. `project_memberships` (a project a user was granted access to
     *      but that has no documents yet).
     *
     * All three are tenant-scoped (R30): only the active team's keys
     * surface. The FE `<select>` renders one `<option>` per entry.
     */
    public function projects(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->current();

        $fromRegistry = \App\Models\Project::query()
            ->forTenant($tenantId)
            ->pluck('project_key');

        $fromDocuments = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->withTrashed()
            ->whereNotNull('project_key')
            ->distinct()
            ->pluck('project_key');

        $fromMemberships = \App\Models\ProjectMembership::query()
            ->forTenant($tenantId)
            ->whereNotNull('project_key')
            ->distinct()
            ->pluck('project_key');

        $projects = $fromRegistry
            ->concat($fromDocuments)
            ->concat($fromMemberships)
            ->filter(fn ($k) => is_string($k) && trim($k) !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return response()->json(['projects' => $projects]);
    }
}
