<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\KbReviewDisabledException;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Review\KbReviewService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.37/W3 (ADR 0031 §2/§4) — HTTP surface (R44) of Digitization Review:
 *   GET  /api/admin/kb/documents/{id}/review-summary            → read status
 *   PATCH /api/admin/kb/documents/{id}/pages/{page}/review-status → mark reviewed
 *   POST /api/admin/kb/documents/{id}/review-approve             → approve (auto -> human)
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

    public function markPageReviewed(Request $request, int $id, int $page): JsonResponse
    {
        try {
            $review = $this->reviews->markPageReviewed($this->find($id), $page, $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            // KbReviewService's page_number >= 1 guard — R14: a client
            // input error is 422, never a 500.
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
