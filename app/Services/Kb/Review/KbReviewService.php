<?php

declare(strict_types=1);

namespace App\Services\Kb\Review;

use App\Exceptions\KbReviewDisabledException;
use App\Models\KbCanonicalAudit;
use App\Models\KbDocumentPageReview;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiExplorerService;
use App\Support\Canonical\GenerationSource;
use Illuminate\Support\Facades\DB;

/**
 * v8.37/W3 (ADR 0031) — the shared core (R44) behind Digitization Review:
 * per-page review progress and document approval (the `auto -> human`
 * transition, ADR 0014, branched on canonicity per §4). Every mutating
 * method throws {@see KbReviewDisabledException} when the feature flag
 * (`kb.review.enabled`, `KB_DIGITIZATION_REVIEW_ENABLED`) is off (R43) —
 * never a silent no-op. Every surface — CLI (`kb:review`), HTTP
 * ({@see \App\Http\Controllers\Api\Admin\KbReviewController}) and MCP
 * (read-only, {@see \App\Mcp\Tools\KbReviewStatusTool}) — adapts this ONE
 * core; none of the three re-implements the logic below.
 *
 * Approval on a CANONICAL document delegates to
 * {@see WikiExplorerService::promote()}, which already performs the exact
 * same transition (audited, transactional) for its own callers. A
 * NON-canonical document (the ordinary OCR'd-scan case) gets the identical
 * generation_source flip and audit event, implemented directly here rather
 * than by relaxing WikiExplorerService::promote() — that method
 * unconditionally sets canonical_status='accepted', which is correct for
 * its existing (canonical-only) callers and would stamp a false canonical
 * fact on a non-canonical row.
 */
class KbReviewService
{
    public function __construct(
        private readonly WikiExplorerService $wikiExplorer,
    ) {}

    /**
     * Upsert a page's review state on the (tenant, document, page) unique
     * key — re-setting an already-touched page is an idempotent no-op with
     * a fresh reviewed_by/reviewed_at (or a clear of both, when reverting
     * to unreviewed), never a second row (ADR 0031 §2). `$status` is the
     * ADR 0031 §9 `--status=` value — `reviewed` or `unreviewed`; a page can
     * be legitimately reverted (a reviewer un-marks a page they clicked by
     * mistake), so this is not a one-way "mark reviewed" action.
     *
     * R21 (Copilot PR #494 round 3) — a plain `updateOrCreate()` is a SELECT
     * then an INSERT/UPDATE, not one atomic statement: two reviewers marking
     * the SAME page concurrently can both miss the row on the SELECT and
     * both attempt an INSERT, so one of them would hit the unique
     * constraint as an uncaught exception instead of the promised
     * idempotent upsert. `Model::upsert()` compiles to the database's
     * native single-statement upsert (`INSERT ... ON CONFLICT DO UPDATE` /
     * `ON DUPLICATE KEY UPDATE`), so the database itself — not two round
     * trips from PHP — resolves the race; the loser's insert becomes the
     * update, never an exception.
     *
     * Copilot PR #494 round 4 — a page number is validated against the
     * document's OWN recorded page count, not just "is it >= 1". Without
     * this, `--page=999` on a one-page conversion silently inserted a valid
     * row and made {@see documentReviewSummary()} report a phantom reviewed
     * page; a document that was never converted through OCR/PDF processing
     * (no `metadata.converter.page_count` at all) was accepted just as
     * readily. Both are now a defined failure, not a defined success.
     *
     * @throws \InvalidArgumentException  page_number is 1-based (ADR 0031
     *     §2) and must not exceed the document's recorded
     *     `metadata.converter.page_count`; a document with no recorded page
     *     count (never converted) is rejected outright — there is nothing
     *     to review yet. `$status` must be one of
     *     {@see KbDocumentPageReview::STATUS_REVIEWED} /
     *     {@see KbDocumentPageReview::STATUS_UNREVIEWED}. This is the
     *     application-layer guard every surface (CLI, HTTP, and — read-only
     *     — MCP) funnels through (R44's "one core"). Postgres additionally
     *     enforces `page_number >= 1` with a CHECK constraint as
     *     defense-in-depth (Copilot PR #494); SQLite cannot ALTER TABLE ADD
     *     a CHECK after creation, so this guard is the ONLY enforcement of
     *     that half under the test driver — the page-count ceiling has no
     *     DB-level counterpart at all (it depends on JSON metadata) and is
     *     enforced ONLY here.
     */
    public function setPageReviewStatus(KnowledgeDocument $document, int $pageNumber, string $status, string $actor): KbDocumentPageReview
    {
        $this->assertEnabled();

        if ($pageNumber < 1) {
            throw new \InvalidArgumentException("page_number must be >= 1, got {$pageNumber}.");
        }

        if (! in_array($status, [KbDocumentPageReview::STATUS_REVIEWED, KbDocumentPageReview::STATUS_UNREVIEWED], true)) {
            throw new \InvalidArgumentException("status must be '".KbDocumentPageReview::STATUS_REVIEWED."' or '".KbDocumentPageReview::STATUS_UNREVIEWED."', got '{$status}'.");
        }

        $pageCount = $this->pageCount($document);
        if ($pageCount === null) {
            throw new \InvalidArgumentException("Document {$document->id} has no recorded page_count (metadata.converter.page_count) — it was never converted through OCR/PDF processing, so there is nothing to review.");
        }
        if ($pageNumber > $pageCount) {
            throw new \InvalidArgumentException("page_number {$pageNumber} exceeds document {$document->id}'s recorded page_count ({$pageCount}).");
        }

        $tenantId = (string) $document->tenant_id;
        $isReviewed = $status === KbDocumentPageReview::STATUS_REVIEWED;

        KbDocumentPageReview::query()->upsert(
            [
                [
                    'tenant_id' => $tenantId,
                    'knowledge_document_id' => $document->id,
                    'page_number' => $pageNumber,
                    'status' => $status,
                    'reviewed_by' => $isReviewed ? $this->resolveUserId($actor) : null,
                    'reviewed_at' => $isReviewed ? now() : null,
                ],
            ],
            ['tenant_id', 'knowledge_document_id', 'page_number'],
            ['status', 'reviewed_by', 'reviewed_at'],
        );

        return KbDocumentPageReview::query()
            ->where('tenant_id', $tenantId)
            ->where('knowledge_document_id', $document->id)
            ->where('page_number', $pageNumber)
            ->firstOrFail();
    }

    /**
     * A single page's review status — the per-page counterpart of
     * {@see documentReviewSummary()} (ADR 0031 §9's `GET .../pages/{n}`
     * read contract). A page that has never had a row is reported as
     * `unreviewed` rather than 404ing — "never touched" and "unreviewed"
     * are the same fact for a page that exists. A page number beyond the
     * document's recorded page count is refused, mirroring
     * {@see setPageReviewStatus()}'s write-side guard, so a caller cannot
     * probe for a "phantom" page that could never legitimately exist.
     *
     * Copilot PR #494 round 5 — a document with NO recorded page count
     * (never converted) used to skip the upper-bound check entirely,
     * so `pages/999` on such a document returned 200 `unreviewed` for a
     * page number that has no basis to exist. `setPageReviewStatus()`
     * already refuses that same document on the write side; the read
     * side must refuse it too, or the two methods disagree about what a
     * "valid page" is for the exact same document.
     *
     * @return array{page_number: int, status: string, reviewed_by: ?int, reviewed_at: ?\Illuminate\Support\Carbon}
     *
     * @throws \InvalidArgumentException  page_number < 1, the document has
     *     no recorded page_count, or page_number exceeds it.
     */
    public function pageReviewStatus(KnowledgeDocument $document, int $pageNumber): array
    {
        if ($pageNumber < 1) {
            throw new \InvalidArgumentException("page_number must be >= 1, got {$pageNumber}.");
        }

        $pageCount = $this->pageCount($document);
        if ($pageCount === null) {
            throw new \InvalidArgumentException("document {$document->id} has no recorded page_count; page-level review status is unavailable until it is converted.");
        }
        if ($pageNumber > $pageCount) {
            throw new \InvalidArgumentException("page_number {$pageNumber} exceeds document {$document->id}'s recorded page_count ({$pageCount}).");
        }

        $row = KbDocumentPageReview::query()
            ->where('tenant_id', (string) $document->tenant_id)
            ->where('knowledge_document_id', $document->id)
            ->where('page_number', $pageNumber)
            ->first();

        return [
            'page_number' => $pageNumber,
            'status' => $row->status ?? KbDocumentPageReview::STATUS_UNREVIEWED,
            'reviewed_by' => $row->reviewed_by ?? null,
            'reviewed_at' => $row->reviewed_at ?? null,
        ];
    }

    /**
     * Derived counts (never a cached boolean, ADR 0031 §2) — there is
     * nothing to keep in sync when a correction re-chunks the document into
     * a different page count.
     *
     * Copilot PR #494 round 4 — `total` is now anchored on the document's
     * own recorded `metadata.converter.page_count` whenever it is known,
     * not on how many `kb_document_page_reviews` rows happen to exist.
     * Before this fix, reviewing page 1 of a freshly-converted 10-page
     * document reported `total=1` (the one row that existed) instead of
     * the document's actual 10 pages — every page that had not YET been
     * touched was invisible to the caller, not merely unreviewed. A
     * document with no recorded page count (never converted) falls back to
     * counting existing rows — the best available answer, not a guess —
     * which is also exactly the pre-fix behaviour for that one case, so a
     * document review is never falsely reported as 0/0/0 once conversion
     * metadata exists.
     *
     * Copilot PR #494 round 5 — when the page count is known, the
     * reviewed-row query is now scoped to `page_number <= $pageCount`.
     * Before this fix a correction that re-chunked a document into fewer
     * pages left the reviewed rows for the pages that no longer exist
     * still in the table, and this method counted ALL of them, only
     * clamping the reported TOTAL with `min()` — so page 10's stale
     * `reviewed` row survived a re-chunk to 2 pages and misattributed
     * itself onto a page that never got reviewed, reporting `reviewed: 1`
     * out of a genuinely-untouched document. Excluding rows beyond the
     * current page count at the query itself (not after counting) is the
     * only way the summary reflects the document as it stands today.
     *
     * @return array{total: int, reviewed: int, unreviewed: int}
     */
    public function documentReviewSummary(KnowledgeDocument $document): array
    {
        $tenantId = (string) $document->tenant_id;
        $pageCount = $this->pageCount($document);

        $reviewedCount = KbDocumentPageReview::query()
            ->where('tenant_id', $tenantId)
            ->where('knowledge_document_id', $document->id)
            ->where('status', KbDocumentPageReview::STATUS_REVIEWED)
            ->when($pageCount !== null, fn ($query) => $query->where('page_number', '<=', $pageCount))
            ->count();

        if ($pageCount !== null) {
            return [
                'total' => $pageCount,
                'reviewed' => $reviewedCount,
                'unreviewed' => max($pageCount - $reviewedCount, 0),
            ];
        }

        $unreviewedCount = KbDocumentPageReview::query()
            ->where('tenant_id', $tenantId)
            ->where('knowledge_document_id', $document->id)
            ->where('status', KbDocumentPageReview::STATUS_UNREVIEWED)
            ->count();

        return [
            'total' => $reviewedCount + $unreviewedCount,
            'reviewed' => $reviewedCount,
            'unreviewed' => $unreviewedCount,
        ];
    }

    /**
     * The document's recorded page count from OCR/PDF conversion
     * (`metadata.converter.page_count`, set by {@see \App\Services\Kb\Ocr\OcrConverter}
     * / {@see \App\Services\Kb\Converters\PdfConverter}), or `null` when the
     * document was never converted (no page-level structure exists to
     * validate against). `0` and negative values are treated as absent —
     * they are not a valid page count to bound anything against.
     */
    private function pageCount(KnowledgeDocument $document): ?int
    {
        $raw = $document->metadata['converter']['page_count'] ?? null;
        if (! is_int($raw) && ! is_numeric($raw)) {
            return null;
        }

        $count = (int) $raw;

        return $count > 0 ? $count : null;
    }

    /**
     * Approve a document — the `auto -> human` transition, branched on
     * canonicity (ADR 0031 §4). Both branches write exactly one
     * `kb_canonical_audit` row (`event_type = 'promoted'`) in the SAME
     * transaction as the column flip; no "approved but unaudited" state is
     * reachable. Refuses (without error) a document already `human`, so a
     * double-click / double-call is a safe no-op — same posture as
     * WikiExplorerService::promote().
     *
     * R21 (Copilot PR #494 round 2) — the whole method runs inside ONE
     * transaction that `lockForUpdate()`s the tenant-scoped document row
     * FIRST and re-reads `is_canonical` / `generation_source` from that
     * locked row, not from the `$document` argument the caller passed in
     * (which may be stale by the time this runs). This serializes
     * concurrent approve() calls on the SAME document — including the
     * canonical branch, which delegates to
     * {@see WikiExplorerService::promote()} INSIDE the held lock: promote()
     * opens its own (nested, savepoint-backed) transaction and reads/writes
     * the row while this method still holds the outer row lock, so a second
     * concurrent approve() blocks on the SELECT ... FOR UPDATE until the
     * first one commits, then re-reads the now-`human` row and returns the
     * safe `not_auto` no-op instead of racing to a duplicate audit row.
     *
     * @return array{approved: bool, reason?: string}
     */
    public function approve(KnowledgeDocument $document, string $actor): array
    {
        $this->assertEnabled();

        $tenantId = (string) $document->tenant_id;
        $documentId = (int) $document->id;

        return DB::transaction(function () use ($tenantId, $documentId, $actor): array {
            /** @var KnowledgeDocument $locked */
            $locked = KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->lockForUpdate()
                ->findOrFail($documentId);

            if ((bool) $locked->is_canonical) {
                $result = $this->wikiExplorer->promote($locked, $actor);

                return [
                    'approved' => (bool) ($result['promoted'] ?? false),
                    'reason' => $result['reason'] ?? null,
                ];
            }

            if ((string) ($locked->generation_source ?? GenerationSource::Human->value) !== GenerationSource::Auto->value) {
                return ['approved' => false, 'reason' => 'not_auto'];
            }

            $before = ['generation_source' => (string) $locked->generation_source];

            // Copilot PR #494 round 4 — save() returns false when a model
            // event vetoes the write (e.g. a `saving` observer). Ignoring
            // that would let the transaction fall through to writing the
            // 'promoted' audit row below while generation_source is STILL
            // 'auto' on disk — an approval that is audited but never
            // happened. Throwing here rolls back the whole transaction
            // (including the row lock), so neither the flip nor its audit
            // row survives a vetoed save.
            if (! $locked->forceFill(['generation_source' => GenerationSource::Human->value])->save()) {
                throw new \RuntimeException("Failed to persist generation_source=human for document {$documentId} (tenant {$tenantId}); a model event vetoed the save.");
            }

            if ((bool) config('kb.canonical.audit_enabled', true)) {
                KbCanonicalAudit::create([
                    'tenant_id' => $tenantId,
                    'project_key' => (string) $locked->project_key,
                    'doc_id' => $locked->doc_id,
                    'slug' => $locked->slug,
                    'event_type' => 'promoted',
                    'actor' => $actor,
                    'before_json' => $before,
                    'after_json' => ['generation_source' => GenerationSource::Human->value],
                    'metadata_json' => ['source' => 'kb_review_approve_non_canonical'],
                ]);
            }

            return ['approved' => true];
        });
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('kb.review.enabled', false)) {
            throw new KbReviewDisabledException();
        }
    }

    /**
     * `reviewed_by` is a nullable FK to `users`. Only an actor shaped
     * `user:{id}` resolves to one; a CLI/system/agent actor leaves it null
     * rather than guessing an owning user.
     */
    private function resolveUserId(string $actor): ?int
    {
        if (preg_match('/^user:(\d+)$/', $actor, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
