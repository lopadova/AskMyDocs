<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiReviewer;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.11/P7 — HTTP surface (R44) of cross-model review:
 *   POST /api/admin/kb/documents/{id}/wiki-review → review an auto-tier doc
 * Delegates to {@see AutoWikiReviewer}; tenant-scoped (R30), RBAC-gated by the
 * admin KB route group (R32 matrix entry).
 */
final class KbWikiReviewController extends Controller
{
    public function __construct(
        private readonly AutoWikiReviewer $reviewer,
        private readonly TenantContext $tenants,
    ) {}

    public function review(int $id): JsonResponse
    {
        $doc = KnowledgeDocument::query()
            ->forTenant($this->tenants->current())
            ->find($id);
        if ($doc === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        return response()->json(['data' => $this->reviewer->review($doc)]);
    }
}
