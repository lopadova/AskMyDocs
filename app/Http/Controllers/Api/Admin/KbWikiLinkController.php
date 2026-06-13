<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiGraphLinker;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.11/P2 — HTTP surface (R44) of auto-wiki graph canonicalization:
 *   POST /api/admin/kb/documents/{id}/wiki-link → (re)build the doc's auto graph
 * Delegates to {@see AutoWikiGraphLinker}; tenant-scoped (R30), RBAC-gated by the
 * admin KB route group (R32 matrix entry).
 */
final class KbWikiLinkController extends Controller
{
    public function __construct(
        private readonly AutoWikiGraphLinker $linker,
        private readonly TenantContext $tenants,
    ) {}

    /** Rebuild the navigable graph (nodes + inferred edges) for a document. */
    public function rebuild(int $id): JsonResponse
    {
        $doc = KnowledgeDocument::query()
            ->forTenant($this->tenants->current())
            ->find($id);
        if ($doc === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        $result = $this->linker->link($doc);

        return response()->json(['data' => $result]);
    }
}
