<?php

namespace App\Http\Controllers\Api\Admin;

use App\Services\Admin\KbTreeService;
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
}
