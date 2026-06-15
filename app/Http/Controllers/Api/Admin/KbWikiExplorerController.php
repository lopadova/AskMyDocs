<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiExplorerService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.11/P10 — HTTP surface (R44) of the Wiki Explorer:
 *   GET  /api/admin/kb/wiki-pages  ?project_key=&tier=&limit=  → list pages
 *   POST /api/admin/kb/documents/{id}/wiki-promote             → promote auto→human
 *   POST /api/admin/kb/documents/{id}/wiki-discard             → soft-delete auto
 * Delegates to {@see WikiExplorerService}; tenant-scoped (R30), RBAC-gated by the
 * admin KB route group (R32 matrix entry).
 */
final class KbWikiExplorerController extends Controller
{
    public function __construct(
        private readonly WikiExplorerService $explorer,
        private readonly TenantContext $tenants,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tier' => ['sometimes', Rule::in(['all', 'auto', 'human'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json([
            'data' => $this->explorer->list(
                $this->tenants->current(),
                $validated['project_key'] ?? null,
                $validated['tier'] ?? 'all',
                (int) ($validated['limit'] ?? 100),
            ),
        ]);
    }

    public function promote(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->explorer->promote($this->find($id), $this->actor($request))]);
    }

    public function discard(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->explorer->discard($this->find($id), $this->actor($request))]);
    }

    private function find(int $id): KnowledgeDocument
    {
        $doc = KnowledgeDocument::query()
            ->forTenant($this->tenants->current())
            ->find($id);
        if ($doc === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        return $doc;
    }

    private function actor(Request $request): string
    {
        return 'admin:'.(string) ($request->user()?->id ?? 'unknown');
    }
}
