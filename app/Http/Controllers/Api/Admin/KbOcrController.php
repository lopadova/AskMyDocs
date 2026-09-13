<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Ocr\OcrService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.36 / ADR 0029 — the HTTP surface of OCR (R44).
 *
 *   GET  /api/admin/kb/documents/{id}/ocr   what OCR recorded on a document
 *   POST /api/admin/kb/documents/{id}/ocr   re-run OCR (queues the ingestion
 *                                            with OCR forced) — 202
 *
 * Auth: `auth:sanctum` + `role:admin|super-admin` (route group); R32 matrix
 * row `/api/admin/kb/documents/1/ocr`. R30 — every lookup is tenant-scoped.
 * With OCR disabled the POST answers 422 (R43 — never a silent 200).
 */
final class KbOcrController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly OcrService $ocr,
    ) {}

    public function status(int $id): JsonResponse
    {
        $document = $this->findOr404($id);

        return response()->json(['data' => $this->ocr->status($document)]);
    }

    public function rerun(Request $request, int $id): JsonResponse
    {
        $document = $this->findOr404($id);
        // Behind auth:sanctum — an unauthenticated request never reaches here.
        $actor = 'user:'.$request->user()->id;

        return response()->json(['data' => $this->ocr->rerun($document, $actor)], 202);
    }

    private function findOr404(int $id): KnowledgeDocument
    {
        $document = KnowledgeDocument::query()->forTenant($this->tenant->current())->find($id);
        if ($document === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        return $document;
    }
}
