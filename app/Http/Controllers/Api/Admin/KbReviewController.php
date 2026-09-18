<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\KbReviewDisabledException;
use App\Models\KbDocumentPageReview;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Review\KbReviewService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.37/W3 (ADR 0031 §2/§4/§9) — HTTP surface (R44) of Digitization Review:
 *   GET   /api/admin/kb/documents/{id}/review-summary              → document-wide aggregate read
 *   GET   /api/admin/kb/documents/{id}/pages/{page}                → single-page read (ADR 0031 §9's documented contract)
 *   PATCH /api/admin/kb/documents/{id}/pages/{page}/review-status  → set a page's status (reviewed|unreviewed)
 *   POST  /api/admin/kb/documents/{id}/approve                     → approve (auto -> human)
 * Delegates to {@see KbReviewService}; tenant-scoped (R30), RBAC-gated by the
 * same admin KB route group as the representative `/api/admin/kb/evidence-tiers`
 * R32 matrix row.
 */
final class KbReviewController extends Controller
{
    public function __construct(
        private readonly KbReviewService $reviews,
        private readonly TenantContext $tenants,
    ) {}

    public function summary(int $id): JsonResponse
    {
        // ADR 0031 §1 — the HTTP surface gates ALL review endpoints
        // (including this read) with a clean 404 when off, even though
        // KbReviewService::documentReviewSummary() itself is intentionally
        // ungated at the service layer (see its docblock: a pure read used
        // internally does not need the flag). This is the surface-level
        // "the whole capability is inert when off" contract, kept explicit
        // here rather than relying on a service throw that a read path
        // deliberately does not have.
        if (! (bool) config('kb.review.enabled', false)) {
            throw new KbReviewDisabledException();
        }

        return response()->json(['data' => $this->reviews->documentReviewSummary($this->find($id))]);
    }

    public function pageStatus(int $id, int $page): JsonResponse
    {
        // Same "whole capability inert when off" posture as summary() above.
        if (! (bool) config('kb.review.enabled', false)) {
            throw new KbReviewDisabledException();
        }

        try {
            $status = $this->reviews->pageReviewStatus($this->find($id), $page);
        } catch (\InvalidArgumentException $e) {
            // Out-of-range page: an addressing failure, not a body-shape
            // one — 404, mirroring the "document not found" 404 below.
            throw new NotFoundHttpException($e->getMessage());
        }

        return response()->json(['data' => [
            'page_number' => $status['page_number'],
            'status' => $status['status'],
            'reviewed_by' => $status['reviewed_by'],
            'reviewed_at' => optional($status['reviewed_at'])->toIso8601String(),
        ]]);
    }

    public function markPageReviewed(Request $request, int $id, int $page): JsonResponse
    {
        $status = (string) $request->input('status', KbDocumentPageReview::STATUS_REVIEWED);

        try {
            $review = $this->reviews->setPageReviewStatus($this->find($id), $page, $status, $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            // KbReviewService's page_number / page_count / status guards —
            // R14: a client input error is 422, never a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'page_number' => $review->page_number,
            'status' => $review->status,
            'reviewed_by' => $review->reviewed_by,
            'reviewed_at' => optional($review->reviewed_at)->toIso8601String(),
        ]]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->reviews->approve($this->find($id), $this->actor($request))]);
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
        return 'user:'.(string) ($request->user()?->id ?? 'unknown');
    }
}
