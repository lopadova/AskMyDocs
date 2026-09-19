<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\KbReviewDisabledException;
use App\Models\KbDocumentPageReview;
use App\Models\KbTextCorrectionCandidate;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Review\KbReviewService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.37/W3 (ADR 0031 §2/§4/§6/§9) — HTTP surface (R44) of Digitization Review:
 *   GET   /api/admin/kb/documents/{id}/review-summary              → document-wide aggregate read
 *   GET   /api/admin/kb/documents/{id}/pages/{page}                → single-page read (ADR 0031 §9's documented contract)
 *   PATCH /api/admin/kb/documents/{id}/pages/{page}/review-status  → set a page's status (reviewed|unreviewed)
 *   POST  /api/admin/kb/documents/{id}/approve                     → approve (auto -> human)
 *   GET   /api/admin/kb/documents/{id}/corrections                 → pending correction-candidate queue for one document
 *   POST  /api/admin/kb/corrections/{id}/approve                   → apply a candidate (ADR 0031 §6)
 *   POST  /api/admin/kb/corrections/{id}/reject                    → deny a candidate without applying it
 * Delegates to {@see KbReviewService}; tenant-scoped (R30), RBAC-gated by the
 * same admin KB route group as the representative `/api/admin/kb/evidence-tiers`
 * R32 matrix row. The correction-candidate endpoints are the ONLY place a
 * candidate can be approved/rejected (ADR 0031 §8 — no MCP write of that
 * decision exists; `KbProposeTextCorrectionTool` only ever creates one).
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

    /**
     * GET /api/admin/kb/documents/{id}/corrections
     *
     * The pending correction-candidate queue for one document (ADR 0031
     * §6) — the review UI's counterpart to `KbProposeTextCorrectionTool`'s
     * writes. Same "whole capability inert when off" posture as the other
     * reads above.
     *
     * R3 (Copilot PR #496 round 1, finding #5) — bounded + paginated: an
     * unbounded `->get()` on a document with a large pending queue (a
     * misbehaving or adversarial agent proposing repeatedly) loads every
     * row into memory on every call. `?limit=` is capped by
     * `kb.review.corrections_page_size` (default 50); `?offset=` pages
     * through the rest. `has_more` tells the caller whether another page
     * exists without a second COUNT query.
     *
     * Round 7 (Copilot PR #496, previously-missed MEDIUM) — the query
     * itself moved into {@see KbReviewService::pendingCorrections()} (R44):
     * this method now only clamps the HTTP-specific `?limit=`/`?offset=`
     * inputs and shapes the JSON response, matching every sibling method
     * on this controller.
     */
    public function corrections(Request $request, int $id): JsonResponse
    {
        if (! (bool) config('kb.review.enabled', false)) {
            throw new KbReviewDisabledException();
        }

        $document = $this->find($id);

        $pageSize = max(1, (int) config('kb.review.corrections_page_size', 50));
        $limit = min($pageSize, max(1, (int) $request->integer('limit', $pageSize)));
        $offset = max(0, (int) $request->integer('offset', 0));

        $result = $this->reviews->pendingCorrections($document, $limit, $offset);
        $candidates = $result['candidates'];
        $hasMore = $result['has_more'];

        return response()->json(['data' => $candidates->map(fn (KbTextCorrectionCandidate $c): array => [
            'id' => $c->id,
            'page_number' => $c->page_number,
            'old_text' => $c->old_text,
            'new_text' => $c->new_text,
            'rationale' => $c->rationale,
            'proposed_by' => $c->proposed_by,
            'created_at' => $c->created_at?->toIso8601String(),
        ])->values(), 'meta' => ['limit' => $limit, 'offset' => $offset, 'has_more' => $hasMore]]);
    }

    /**
     * POST /api/admin/kb/corrections/{id}/approve
     *
     * Applies a correction candidate through {@see KbReviewService::approveCorrection()}
     * (ADR 0031 §6, R21). `already_consumed` is a genuine conflict — a
     * concurrent request already resolved this SAME candidate — and returns
     * HTTP **409** per ADR 0031 §6 ("the second transaction's
     * `lockForUpdate()` sees `status != 'pending'` and the caller receives
     * 409 `already_consumed`", Copilot PR #496 round 1 finding #6).
     * `document_not_found` / `stale_old_text_not_found_or_ambiguous` /
     * `stale_version_no_longer_active` stay 200 with `applied: false` —
     * they are decided outcomes of a request that itself was well-formed,
     * not a conflict with another request.
     */
    public function approveCorrection(Request $request, int $id): JsonResponse
    {
        $candidate = $this->findCandidate($id);
        $result = $this->reviews->approveCorrection($candidate, $this->actor($request), $request->user()?->id);

        return response()->json(['data' => $result], $this->correctionOutcomeStatus($result));
    }

    /**
     * POST /api/admin/kb/corrections/{id}/reject
     *
     * Denies a correction candidate without applying it
     * ({@see KbReviewService::rejectCorrection()}, R21). Same 409-on-conflict
     * mapping as {@see approveCorrection()}.
     */
    public function rejectCorrection(Request $request, int $id): JsonResponse
    {
        $candidate = $this->findCandidate($id);
        $result = $this->reviews->rejectCorrection($candidate, $request->user()?->id);

        return response()->json(['data' => $result], $this->correctionOutcomeStatus($result));
    }

    /**
     * @param  array{applied?: bool, rejected?: bool, reason?: string}  $result
     */
    private function correctionOutcomeStatus(array $result): int
    {
        return ($result['reason'] ?? null) === 'already_consumed' ? 409 : 200;
    }

    private function findCandidate(int $id): KbTextCorrectionCandidate
    {
        $candidate = KbTextCorrectionCandidate::query()
            ->forTenant($this->tenants->current())
            ->find($id);
        if ($candidate === null) {
            throw new NotFoundHttpException('Correction candidate not found.');
        }

        return $candidate;
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
