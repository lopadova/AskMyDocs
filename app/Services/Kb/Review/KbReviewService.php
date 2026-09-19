<?php

declare(strict_types=1);

namespace App\Services\Kb\Review;

use App\Exceptions\KbReviewDisabledException;
use App\Exceptions\KbReviewRateLimitedException;
use App\Models\KbCanonicalAudit;
use App\Models\KbDocumentPageReview;
use App\Models\KbTextCorrectionCandidate;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiExplorerService;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\Versioning\ArtifactPublishFailedException;
use App\Services\Kb\Versioning\DocumentVersionService;
use App\Services\Kb\Versioning\ReembedTargetNoLongerActiveException;
use App\Support\Canonical\GenerationSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

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
        private readonly DocumentVersionService $versions,
        private readonly DocumentIngestor $ingestor,
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

            // Copilot PR #494 round 12 (previously missed) — this flip's
            // DURABILITY against a later AutoWiki compile pass depends
            // entirely on AutoWikiCompiler::apply()'s firewall, which only
            // preserves a human value for is_canonical || OCR-origin rows
            // (rounds 9/10). Approving a non-canonical, non-OCR document
            // here (an AutoWiki-enriched raw-markdown row,
            // generation_source=auto by construction) would write a
            // 'promoted' audit row claiming a durable approval, then a
            // subsequent compile pass silently flips it right back to
            // 'auto' — the audit trail would lie about the document's
            // actual state. Restrict this branch to the ONE case the
            // firewall actually protects: OCR-origin documents (ADR 0031's
            // whole premise — Digitization Review gates OCR output, not
            // raw markdown AutoWiki already owns).
            $isOcrOrigin = (($locked->metadata['converter']['provenance'] ?? null) === 'ocr');
            if (! $isOcrOrigin) {
                return ['approved' => false, 'reason' => 'not_ocr_origin'];
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

    /**
     * ADR 0031 §6 — record an agent-proposed text-correction CANDIDATE.
     * Never touches `knowledge_documents` or its chunks; writes only to
     * `kb_text_correction_candidates`. `old_text` must occur EXACTLY once on
     * the CURRENT live version of `$pageNumber` — zero or multiple
     * occurrences both refuse (ADR 0031 §6: "ambiguous or absent →
     * refused, never 'first match'"). `new_text` <= 4000 chars, `rationale`
     * <= 500 chars (app-enforced, ADR 0031 §7).
     *
     * Idempotent via the DB-enforced `idempotency_key`: a replayed call
     * (identical 7-tuple of tenant/actor/document/version/page/old/new)
     * returns the SAME existing row, checked BEFORE the rate limiter is
     * touched — a replay never spends the actor's budget.
     *
     * Two independent locks (Copilot PR #496 round 2 — round 1's
     * idempotency-key-only lock left two gaps):
     *
     * 1. A per-TENANT+ACTOR `Cache::lock()` wraps the WHOLE check-then-act
     *    sequence (live-version lock, existing-check, rate-limit
     *    check-and-hit, insert). Round 1's lock was keyed on the
     *    idempotency key, so it only serialized IDENTICAL proposals — two
     *    concurrent DIFFERENT proposals from the SAME actor (different
     *    old/new text, or different documents entirely) could both pass
     *    `RateLimiter::tooManyAttempts()` before either called `hit()`,
     *    over-spending the actor's hourly budget. A per-actor lock
     *    serializes ALL of that actor's proposals, closing that gap
     *    entirely (and subsumes the identical-proposal case round 1's lock
     *    covered).
     * 2. Inside that lock, `DocumentVersionService::currentVersionFor($document,
     *    lock: true)` locks the family's ACTUAL live row, inside a
     *    `DB::transaction()`, before its content is read for the old_text
     *    validation — closing the propose-time analogue of the
     *    approve-side finding #1: a concurrent re-ingest cannot archive
     *    this exact row out from under the validation + insert below (it
     *    blocks on this lock until the transaction commits).
     *
     * ADR 0031 §6 — "every accepted, denied, and replayed call writes an
     * audit row". Every DENIED outcome (bad input, old_text not found /
     * ambiguous, rate-limited) is audited, not only the rate-limit case
     * round 1 covered — round 1 additionally wrote validation-denial audits
     * INSIDE the transaction they'd throw out of, which rolled the audit
     * row back along with everything else; the inner closure here returns
     * a `{ok, ...}` result instead of throwing, so the transaction always
     * COMMITS, and the audit-then-throw for a denial happens AFTER, outside
     * it. The accepted path still writes the candidate and its audit row in
     * ONE transaction (finding #8): an audit-write failure there rolls the
     * candidate insert back too — never a silently unaudited candidate.
     *
     * @throws \InvalidArgumentException  bad page number, oversize
     *     new_text/rationale, empty old_text, or old_text not found /
     *     ambiguous on the page.
     * @throws KbReviewRateLimitedException  the actor's
     *     `kb.review.candidates_per_hour` budget is spent (only reached for
     *     a genuinely NEW candidate, never a replay).
     */
    public function proposeCorrection(
        KnowledgeDocument $document,
        int $pageNumber,
        string $oldText,
        string $newText,
        ?string $rationale,
        string $actor,
    ): KbTextCorrectionCandidate {
        $this->assertEnabled();

        $tenantId = (string) $document->tenant_id;

        // These four guards run before ANY lock/transaction is opened, so
        // their audit write is a plain, standalone insert — never at risk
        // of being rolled back with the exception that follows it.
        if ($pageNumber < 1) {
            $this->auditProposal($tenantId, $document, $actor, null, 'denied', ['reason' => 'invalid_page_number', 'page_number' => $pageNumber]);

            throw new \InvalidArgumentException("page_number must be >= 1, got {$pageNumber}.");
        }
        if (trim($oldText) === '') {
            $this->auditProposal($tenantId, $document, $actor, null, 'denied', ['reason' => 'empty_old_text', 'page_number' => $pageNumber]);

            throw new \InvalidArgumentException('old_text must not be empty.');
        }
        if (mb_strlen($newText) > 4000) {
            $this->auditProposal($tenantId, $document, $actor, null, 'denied', ['reason' => 'new_text_too_long', 'page_number' => $pageNumber]);

            throw new \InvalidArgumentException('new_text must be at most 4000 characters.');
        }
        if ($rationale !== null && mb_strlen($rationale) > 500) {
            $this->auditProposal($tenantId, $document, $actor, null, 'denied', ['reason' => 'rationale_too_long', 'page_number' => $pageNumber]);

            throw new \InvalidArgumentException('rationale must be at most 500 characters.');
        }

        return Cache::lock("kb-review-propose-actor:{$tenantId}:{$actor}", 10)->block(5, function () use (
            $document, $tenantId, $pageNumber, $oldText, $newText, $rationale, $actor,
        ): KbTextCorrectionCandidate {
            // Captured by reference from inside the transaction below, the
            // instant they're computed — BEFORE the insert that can throw
            // — so the conflict-recovery catch block outside the
            // transaction still has them even though the transaction that
            // set them has since rolled back (round 3 fix below explains
            // why that recovery cannot run INSIDE that transaction).
            $conflictLive = null;
            $conflictKey = null;

            try {
                /** @var array{ok: bool, candidate?: KbTextCorrectionCandidate, live?: KnowledgeDocument, reason?: string, extra?: array<string,mixed>, exception?: \Throwable} $outcome */
                $outcome = DB::transaction(function () use (
                    $document, $tenantId, $pageNumber, $oldText, $newText, $rationale, $actor, &$conflictLive, &$conflictKey,
                ): array {
                    $live = $this->versions->currentVersionFor($document, lock: true);

                    // Copilot PR #496 round 4 (previously-missed finding) —
                    // currentVersionFor()'s own documented fallback is
                    // `->first() ?? $document`: when the family has NO row
                    // with status='active' (e.g. every version archived, or
                    // the passed-in $document itself is a dead-end that was
                    // never indexed), it silently hands back whatever
                    // (possibly non-active) $document was passed in rather
                    // than throwing. Proposing — and later approving — a
                    // correction against that row would validate old_text
                    // against content that may already be stale, and
                    // approveCorrection's own `stale_version_no_longer_active`
                    // guard only catches a version that WAS active and
                    // became archived mid-flight, not one that was never
                    // active to begin with. Refuse and audit it here,
                    // exactly like the old_text-not-found denial below.
                    if ($live->status !== 'active') {
                        return [
                            'ok' => false, 'live' => $live, 'reason' => 'document_not_active',
                            'exception' => new \InvalidArgumentException("Document {$live->id} has no active version to propose a correction against (status: {$live->status})."),
                        ];
                    }

                    $located = $this->locatePageOccurrence($this->pageAwareContentFor($live), $pageNumber, $oldText);
                    if ($located === null) {
                        return [
                            'ok' => false, 'live' => $live, 'reason' => 'old_text_not_found_or_ambiguous',
                            'exception' => new \InvalidArgumentException("old_text does not occur exactly once on page {$pageNumber} of document {$live->id}."),
                        ];
                    }

                    $versionHash = (string) $live->version_hash;
                    $key = KbTextCorrectionCandidate::idempotencyKeyFor($tenantId, $actor, (int) $live->id, $versionHash, $pageNumber, $oldText, $newText);
                    $conflictLive = $live;
                    $conflictKey = $key;

                    // forTenant() here is defense-in-depth, not the
                    // correctness mechanism: idempotency_key already
                    // hashes tenantId in, so a cross-tenant collision is
                    // cryptographically infeasible — but every query
                    // against a tenant-aware table funnels through
                    // forTenant() regardless (R30).
                    $existing = KbTextCorrectionCandidate::query()->forTenant($tenantId)->where('idempotency_key', $key)->first();
                    if ($existing !== null) {
                        $this->auditProposal($tenantId, $live, $actor, $existing, 'replayed');

                        return ['ok' => true, 'candidate' => $existing];
                    }

                    $limit = max(0, (int) config('kb.review.candidates_per_hour', 60));
                    $limiterKey = "kb-review-candidates:{$tenantId}:{$actor}";
                    if ($limit > 0 && RateLimiter::tooManyAttempts($limiterKey, $limit)) {
                        return [
                            'ok' => false, 'live' => $live, 'reason' => 'rate_limited', 'extra' => ['limit_per_hour' => $limit],
                            'exception' => new KbReviewRateLimitedException($actor, $limit),
                        ];
                    }
                    if ($limit > 0) {
                        RateLimiter::hit($limiterKey, 3600);
                    }

                    // No inner try/catch here (round 3 fix): if this
                    // insert throws a QueryException, it must propagate
                    // OUT of this transaction so DB::transaction() rolls
                    // it back — the recovery below runs in a fresh
                    // statement, never inside this one.
                    $candidate = KbTextCorrectionCandidate::create([
                        'tenant_id' => $tenantId,
                        'knowledge_document_id' => $live->id,
                        'page_number' => $pageNumber,
                        'version_hash' => $versionHash,
                        'old_text' => $oldText,
                        'new_text' => $newText,
                        'rationale' => $rationale,
                        'idempotency_key' => $key,
                        'status' => KbTextCorrectionCandidate::STATUS_PENDING,
                        'proposed_by' => $actor,
                    ]);

                    $this->auditProposal($tenantId, $live, $actor, $candidate, 'accepted');

                    return ['ok' => true, 'candidate' => $candidate];
                });
            } catch (QueryException $e) {
                if (! $this->isIdempotencyKeyConflict($e)) {
                    throw $e;
                }
                // Copilot PR #496 round 3 — the insert above ran inside
                // `DB::transaction()`; when its closure throws, Laravel
                // rolls that transaction back automatically, so by the
                // time we're here it's already gone. On PostgreSQL,
                // catching a constraint-violation exception WITHOUT
                // rolling back first poisons the transaction (every
                // subsequent statement fails with `25P02` until an
                // explicit ROLLBACK) — running the winner re-read + its
                // audit write INSIDE the same (already-caught, would-be-
                // poisoned) transaction would silently break the exact
                // "degraded lock store" concurrent-double-call path this
                // whole mechanism exists to keep safe. Both statements
                // below run OUTSIDE it, in a clean connection state. A
                // concurrent proposer won the race on the SAME
                // idempotency_key — extremely unlikely now that both the
                // per-document row lock AND the per-actor Cache::lock
                // serialize this whole sequence, but kept as defense-in-
                // depth for a degraded/unavailable lock store (which falls
                // back to a no-op lock rather than failing the request).
                // The loser re-reads the winner's row rather than
                // surfacing a uniqueness violation, and audits it as a
                // replay (ADR 0031 §6: every replayed call is audited).
                $winner = KbTextCorrectionCandidate::query()->forTenant($tenantId)->where('idempotency_key', $conflictKey)->firstOrFail();
                $this->auditProposal($tenantId, $conflictLive, $actor, $winner, 'replayed');

                return $winner;
            }

            if (! $outcome['ok']) {
                $this->auditProposal($tenantId, $outcome['live'], $actor, null, 'denied', array_merge(
                    ['reason' => $outcome['reason'], 'page_number' => $pageNumber],
                    $outcome['extra'] ?? [],
                ));

                throw $outcome['exception'];
            }

            return $outcome['candidate'];
        });
    }

    /**
     * ADR 0031 §6 — one `kb_canonical_audit` row per propose outcome
     * (`accepted` / `denied` / `replayed`). `event_type = 'correction_proposed'`
     * (no DB-level CHECK constraint on that column — see the migration —
     * so a new value needs no migration). Gated by the same
     * `kb.canonical.audit_enabled` killswitch {@see approveCorrection()}
     * honours, for one consistent audit on/off knob across the feature.
     *
     * @param  array<string,mixed>  $extra
     */
    private function auditProposal(string $tenantId, KnowledgeDocument $live, string $actor, ?KbTextCorrectionCandidate $candidate, string $outcome, array $extra = []): void
    {
        if (! (bool) config('kb.canonical.audit_enabled', true)) {
            return;
        }

        KbCanonicalAudit::create([
            'tenant_id' => $tenantId,
            'project_key' => (string) $live->project_key,
            'doc_id' => $live->doc_id,
            'slug' => $live->slug,
            'event_type' => 'correction_proposed',
            'actor' => $actor,
            'before_json' => null,
            'after_json' => $candidate !== null
                ? ['candidate_id' => $candidate->id, 'page_number' => $candidate->page_number, 'old_text' => $candidate->old_text, 'new_text' => $candidate->new_text]
                : null,
            'metadata_json' => array_merge(['source' => 'kb_review_propose_correction', 'outcome' => $outcome], $extra),
        ]);
    }

    /**
     * ADR 0031 §6 — the reviewer's approval, single-use and atomic (R21),
     * in three phases (Copilot PR #496 round 2 — round 1 nested the ENTIRE
     * flow, including `DocumentIngestor::reembedFromMarkdown()`, inside one
     * ambient transaction; that call publishes its artifact and dispatches
     * its canonical-graph job right after its OWN inner commit — still
     * INSIDE round 1's outer transaction — so a later failure in THIS
     * method rolling that outer transaction back would undo the new
     * `knowledge_documents` row while the artifact/job side effects had
     * already happened, leaving orphaned or inconsistent state):
     *
     * 1. **Claim** (own transaction) — `lockForUpdate()`s the candidate row
     *    (refusing any row whose status is no longer `pending`), resolves
     *    and LOCKS the family's ACTUAL live row via
     *    {@see DocumentVersionService::currentVersionFor()}`(lock: true)` —
     *    NOT a bare lock on `$document` (the row the candidate happens to
     *    name, which per that method's own docblock may be an OLDER
     *    version than the family's current live one; round 1 finding #1),
     *    refuses a CANONICAL `$live` (its Markdown carries YAML frontmatter
     *    that {@see DocumentVersionService::contentFor()}'s chunk-
     *    reconstruction fallback explicitly drops — feeding that
     *    frontmatter-less body to `reembedFromMarkdown()` would silently
     *    demote a canonical document; this feature targets the ordinary
     *    OCR/PDF scan, ADR 0031: "non-canonical OCR documents ... canonical
     *    frontmatter keeps its own say" — a canonical document's own
     *    approval path is {@see approve()}'s `WikiExplorerService::promote()`
     *    branch), re-validates `old_text` still occurs exactly once on the
     *    CURRENT live version's page (a correction proposed against
     *    version N must not silently apply to version N+1's different
     *    text), and — on success — ATOMICALLY marks the candidate
     *    `applied` right here, BEFORE the external reembed call. This is
     *    the R21 single-use guarantee: a second, concurrent approval
     *    attempt's `lockForUpdate()` blocks until this transaction commits,
     *    then sees `status != 'pending'` and returns `{applied: false,
     *    reason: 'already_consumed'}` (mapped to HTTP 409 by the
     *    controller) — WITHOUT needing any lock held across phase 2.
     * 2. **Apply** (no ambient transaction — exactly like every other
     *    caller of `DocumentIngestor` uses it, e.g. `ReembedDocumentJob`) —
     *    `reembedFromMarkdown($live, $corrected)` manages its own complete
     *    transaction + post-commit side effects with nothing outside it to
     *    ever roll back. A failure here (most commonly
     *    `ReembedTargetNoLongerActiveException`, when phase 1's `$live` was
     *    itself a stale fallback — see that method's docblock) reverts the
     *    phase-1 claim in a small follow-up transaction (REJECTED for a
     *    decided staleness, PENDING + re-thrown for anything else — an
     *    infra blip a reviewer can simply retry, per R14) rather than
     *    leaving the candidate stranded `applied` with no version.
     * 3. **Record** (own transaction) — the `kb_canonical_audit` row for
     *    the now-successful correction. The candidate's `status`/
     *    `consumed_at`/`consumed_by` are already committed from phase 1, and
     *    phase 2's new document version is already committed too, so a
     *    failure writing THIS row is caught and `Log::critical()`'d rather
     *    than propagated (a narrow, honestly-accepted gap — a successful
     *    correction whose audit row must be hand-reconstructed from the log
     *    — rather than telling the caller the approval failed when it
     *    didn't, or reopening the R21 race phase 1 closes).
     *
     * Accepted residual risk (Copilot PR #496 round 4, H-B) — phases 1 and 2
     * are each individually atomic (a DB transaction, and `reembedFromMarkdown()`'s
     * own top-level transaction, respectively), but there is no cross-process
     * atomicity BETWEEN them: this whole method runs synchronously inside one
     * HTTP/MCP request, not as a queued, retryable job. If the PHP process
     * handling that request is killed (OOM, SIGKILL, host failure) at any
     * point between phase 1's commit and phase 2's `reembedFromMarkdown()`
     * call returning, the candidate is left committed `applied` with NO
     * corresponding new document version ever created — indistinguishable
     * from a normal success without cross-referencing `kb_canonical_audit`
     * for a matching `metadata_json.candidate_id`. Closing this properly
     * needs a lease/outbox + reconciliation-job pattern (detect stuck
     * `applied` candidates with no matching audit row past some staleness
     * threshold, and either finalize or revert them) — a distinct, larger-
     * scope piece of infrastructure than this propose/approve/reject flow,
     * deliberately left as documented future work rather than folded in
     * here. Until then, manual recovery: find candidates with
     * `status='applied'` and no `kb_canonical_audit` row whose
     * `metadata_json->candidate_id` matches; if no corresponding new
     * `knowledge_documents` row exists for that document's family either,
     * the reembed itself never ran — reset the candidate to `pending` via a
     * direct update so a reviewer can retry.
     *
     * @return array{applied: bool, reason?: string, document_id?: int}
     */
    public function approveCorrection(KbTextCorrectionCandidate $candidate, string $actor, ?int $reviewerUserId): array
    {
        $this->assertEnabled();

        $tenantId = (string) $candidate->tenant_id;
        $candidateId = (int) $candidate->id;

        /** @var array{done: bool, result?: array{applied: bool, reason?: string}, live?: KnowledgeDocument, corrected?: string} $claim */
        $claim = DB::transaction(function () use ($tenantId, $candidateId, $reviewerUserId): array {
            /** @var KbTextCorrectionCandidate $locked */
            $locked = KbTextCorrectionCandidate::query()
                ->forTenant($tenantId)
                ->lockForUpdate()
                ->findOrFail($candidateId);

            if ($locked->status !== KbTextCorrectionCandidate::STATUS_PENDING) {
                return ['done' => true, 'result' => ['applied' => false, 'reason' => 'already_consumed']];
            }

            /** @var KnowledgeDocument|null $document */
            $document = KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->find($locked->knowledge_document_id);

            if ($document === null) {
                $locked->forceFill([
                    'status' => KbTextCorrectionCandidate::STATUS_REJECTED,
                    'consumed_at' => now(),
                    'consumed_by' => $reviewerUserId,
                ])->save();

                return ['done' => true, 'result' => ['applied' => false, 'reason' => 'document_not_found']];
            }

            // R21 (finding #1) — the row actually written to in phase 2 is
            // LOCKED here, in this same transaction, before its content is
            // read for the old_text re-validation below.
            $live = $this->versions->currentVersionFor($document, lock: true);

            if ((bool) $live->is_canonical) {
                $locked->forceFill([
                    'status' => KbTextCorrectionCandidate::STATUS_REJECTED,
                    'consumed_at' => now(),
                    'consumed_by' => $reviewerUserId,
                ])->save();

                return ['done' => true, 'result' => ['applied' => false, 'reason' => 'canonical_document_not_supported']];
            }

            $markdown = $this->pageAwareContentFor($live);

            $located = $this->locatePageOccurrence($markdown, (int) $locked->page_number, (string) $locked->old_text);
            if ($located === null) {
                $locked->forceFill([
                    'status' => KbTextCorrectionCandidate::STATUS_REJECTED,
                    'consumed_at' => now(),
                    'consumed_by' => $reviewerUserId,
                ])->save();

                return ['done' => true, 'result' => ['applied' => false, 'reason' => 'stale_old_text_not_found_or_ambiguous']];
            }

            $corrected = substr($markdown, 0, $located['start']).$locked->new_text.substr($markdown, $located['start'] + $located['length']);

            // The atomic claim (R21): marked APPLIED now, before the
            // external reembed call in phase 2 — a concurrent second
            // approval sees status != pending immediately, with no lock
            // held across that call.
            $locked->forceFill([
                'status' => KbTextCorrectionCandidate::STATUS_APPLIED,
                'consumed_at' => now(),
                'consumed_by' => $reviewerUserId,
            ])->save();

            return ['done' => false, 'live' => $live, 'corrected' => $corrected];
        });

        if ($claim['done']) {
            return $claim['result'];
        }

        /** @var KnowledgeDocument $live */
        $live = $claim['live'];
        $corrected = $claim['corrected'];

        // Phase 2 — v8.37/W3b round 1 (findings #2 + #3), now OUTSIDE any
        // ambient transaction (round 2): apply the correction through the
        // SAME core `ReembedDocumentJob`'s artifact-fallback path uses,
        // never a manual Storage::put() at `source_path` +
        // IngestDocumentJob::dispatch(): `source_path` is the ORIGINAL
        // BINARY for an OCR/PDF/image-origin document (the vast majority of
        // documents this feature exists for) — writing corrected Markdown
        // text there would destroy the source the next re-OCR needs.
        // reembedFromMarkdown() stages the corrected text as a NEW
        // version's ARTIFACT (never touches `source_path`), mints a
        // genuinely new `version_hash` + row (preserving
        // `mime_type`/`source_type`), and chunks + embeds SYNCHRONOUSLY
        // inside its OWN `DB::transaction()` — a genuinely TOP-LEVEL one,
        // not a savepoint, so its post-commit artifact publish + canonical-
        // graph dispatch (moot here: phase 1 already refused a canonical
        // `$live`) have no outer transaction left to be undone by. Its own
        // `assertDocumentStillActive()` re-checks under `lockForUpdate()`,
        // inside that transaction, that `$live` is STILL the active row at
        // write time.
        try {
            $newVersion = $this->ingestor->reembedFromMarkdown($live, $corrected);
        } catch (ReembedTargetNoLongerActiveException) {
            DB::transaction(fn () => KbTextCorrectionCandidate::query()
                ->forTenant($tenantId)->lockForUpdate()->whereKey($candidateId)
                ->update(['status' => KbTextCorrectionCandidate::STATUS_REJECTED]));

            return ['applied' => false, 'reason' => 'stale_version_no_longer_active'];
        } catch (ArtifactPublishFailedException $e) {
            // Copilot PR #496 round 4 (H-C) — this exception's own docblock
            // documents it as a POST-COMMIT failure: reembedFromMarkdown()'s
            // internal DB::transaction() has ALREADY committed the new
            // knowledge_documents row + its chunks by the time this is
            // thrown — only the artifact publish step afterwards failed.
            // $e->documentId genuinely exists. Treating this the same as the
            // generic \Throwable branch below (revert phase 1's claim to
            // `pending`) would be wrong twice over: it would mark a
            // correction that DID apply back as unapplied, and a reviewer's
            // retry would then find $live no longer active (superseded by
            // the very row this catch is looking at) and get rejected with
            // `stale_version_no_longer_active` — losing a correction that
            // actually succeeded. Instead: log and carry on as a success,
            // exactly the posture ReembedDocumentJob::logArtifactNotPublished()
            // already takes for the same exception on its own call path —
            // the pointer stays set and an identical re-ingest or
            // `kb:artifacts-backfill` repairs the artifact later.
            Log::warning('KbReviewService::approveCorrection — artifact publish failed after the new version committed; treating the candidate as applied (self-repairing)', [
                'candidate_id' => $candidateId,
                'document_id' => $e->documentId,
                'disk' => $e->disk,
                'markdown_path' => $e->markdownPath,
                'exception' => $e->getMessage(),
            ]);

            $newVersion = KnowledgeDocument::query()->forTenant($tenantId)->findOrFail($e->documentId);
        } catch (\Throwable $e) {
            // R14/R4 — any OTHER infra failure here (embedding provider
            // down, DB unavailable, ...) — everything except
            // ArtifactPublishFailedException, caught above, which is a
            // POST-commit failure and must NOT be reverted — must not leave
            // the candidate stranded `applied` with no corresponding
            // version: revert the phase-1 claim to `pending` (a transient
            // failure, not a decided rejection — a reviewer can simply
            // retry) and surface the failure loudly rather than swallowing
            // it.
            DB::transaction(fn () => KbTextCorrectionCandidate::query()
                ->forTenant($tenantId)->lockForUpdate()->whereKey($candidateId)
                ->update(['status' => KbTextCorrectionCandidate::STATUS_PENDING, 'consumed_at' => null, 'consumed_by' => null]));

            throw $e;
        }

        // Phase 3 — the audit row for the now-successful correction.
        //
        // Copilot PR #496 round 4 (previously-missed MEDIUM finding) — this
        // write is caught and logged rather than left to propagate. By this
        // point the correction has ALREADY applied: phase 1 committed the
        // candidate as `applied` and phase 2 committed the new document
        // version. Letting an audit-write failure (e.g. a momentary DB
        // outage) bubble up as an uncaught exception would tell the caller
        // the approval FAILED when it in fact SUCCEEDED — and a client that
        // reacts to that by retrying the same approve call would then hit
        // `already_consumed` (409) on a candidate that isn't pending
        // anymore, which reads as corruption, not as "it actually worked".
        // A durable outbox/reconciliation path for the audit row itself
        // (so a dropped write is automatically re-materialized rather than
        // only loggable) is deliberately out of scope here — it is the same
        // class of larger-scope work as the phase-1/phase-2 crash window
        // documented on this method's own docblock, and this PR's target is
        // the propose/approve/reject flow, not a general audit-durability
        // subsystem. `Log::critical()` keeps every field needed to hand-
        // reconstruct the row (mirrors `ChatLogManager::log()`'s established
        // "never let logging/auditing failure break an already-successful
        // user-facing outcome" posture, CLAUDE.md §6).
        if ((bool) config('kb.canonical.audit_enabled', true)) {
            try {
                KbCanonicalAudit::create([
                    'tenant_id' => $tenantId,
                    'project_key' => (string) $newVersion->project_key,
                    'doc_id' => $newVersion->doc_id,
                    'slug' => $newVersion->slug,
                    'event_type' => 'updated',
                    'actor' => $actor,
                    'before_json' => ['document_id' => $live->id, 'version_hash' => $live->version_hash, 'old_text' => $candidate->old_text],
                    'after_json' => ['document_id' => $newVersion->id, 'version_hash' => $newVersion->version_hash, 'new_text' => $candidate->new_text, 'page_number' => $candidate->page_number],
                    'metadata_json' => ['source' => 'kb_review_correction_candidate', 'candidate_id' => $candidateId],
                ]);
            } catch (\Throwable $e) {
                Log::critical('KbReviewService::approveCorrection — the correction applied successfully but its audit row failed to write; reconstruct manually from these fields', [
                    'candidate_id' => $candidateId,
                    'tenant_id' => $tenantId,
                    'actor' => $actor,
                    'before_document_id' => $live->id,
                    'before_version_hash' => $live->version_hash,
                    'after_document_id' => $newVersion->id,
                    'after_version_hash' => $newVersion->version_hash,
                    'page_number' => $candidate->page_number,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return ['applied' => true, 'document_id' => $newVersion->id];
    }

    /**
     * ADR 0031 §6 — a reviewer denies a candidate without applying it.
     * Single-use and atomic (R21) via the same `lockForUpdate()` shape as
     * {@see approveCorrection()}; a candidate already consumed (applied or
     * previously rejected) returns `{rejected: false, reason:
     * 'already_consumed'}` rather than re-writing `consumed_at`.
     *
     * @return array{rejected: bool, reason?: string}
     */
    public function rejectCorrection(KbTextCorrectionCandidate $candidate, ?int $reviewerUserId): array
    {
        $this->assertEnabled();

        $tenantId = (string) $candidate->tenant_id;
        $candidateId = (int) $candidate->id;

        return DB::transaction(function () use ($tenantId, $candidateId, $reviewerUserId): array {
            /** @var KbTextCorrectionCandidate $locked */
            $locked = KbTextCorrectionCandidate::query()
                ->forTenant($tenantId)
                ->lockForUpdate()
                ->findOrFail($candidateId);

            if ($locked->status !== KbTextCorrectionCandidate::STATUS_PENDING) {
                return ['rejected' => false, 'reason' => 'already_consumed'];
            }

            $locked->forceFill([
                'status' => KbTextCorrectionCandidate::STATUS_REJECTED,
                'consumed_at' => now(),
                'consumed_by' => $reviewerUserId,
            ])->save();

            return ['rejected' => true];
        });
    }

    /**
     * Copilot PR #496 round 4 (H-A): `DocumentVersionService::contentFor()`
     * falls back to chunk reconstruction (`implode("\n\n")` over
     * `chunk_text`) whenever the version has no retained conversion
     * artifact — e.g. `markdown_only`/`reference_only` retention, or a
     * version ingested before artifact retention existed. For a document
     * chunked by {@see \App\Services\Kb\Chunkers\PdfPageChunker}, the page
     * number lives OUT-OF-BAND on each chunk (`heading_path = "Page N"`,
     * `metadata['page'] = N`) and is never written into `chunk_text`
     * itself — so the reconstructed markdown has NO `## Page N` headers at
     * all, and {@see self::locatePageOccurrence()} unconditionally fails
     * to locate any page section.
     *
     * This re-synthesizes those headers from chunk metadata when the
     * artifact-backed content doesn't already carry them, so a text
     * correction can still be proposed/applied against an artifactless
     * document. It degrades to the plain (headerless) content — the exact
     * previous behaviour, still correct for non-paginated documents — the
     * moment any chunk lacks an integer `page` in its metadata, since that
     * signals a non-`PdfPageChunker` chunking strategy this method cannot
     * reason about.
     */
    private function pageAwareContentFor(KnowledgeDocument $live): string
    {
        $content = (string) $this->versions->contentFor($live)['content'];

        if (preg_match('/^## Page \d+\s*$/m', $content) === 1) {
            return $content;
        }

        $chunks = KnowledgeChunk::query()
            ->forTenant((string) $live->tenant_id)
            ->where('knowledge_document_id', $live->id)
            ->orderBy('chunk_order')
            ->get(['chunk_text', 'metadata']);

        $pages = [];
        foreach ($chunks as $chunk) {
            $metadata = is_array($chunk->metadata) ? $chunk->metadata : [];
            $page = $metadata['page'] ?? null;
            if (! is_int($page)) {
                return $content;
            }
            $pages[$page] = ($pages[$page] ?? '').$chunk->chunk_text."\n\n";
        }

        if ($pages === []) {
            return $content;
        }

        ksort($pages);

        $rebuilt = '';
        foreach ($pages as $number => $body) {
            $rebuilt .= "## Page {$number}\n\n".trim($body)."\n\n";
        }

        return $rebuilt;
    }

    /**
     * The exact byte offset + length of `$oldText`'s SOLE occurrence within
     * `$pageNumber`'s section of `$markdown`, split on the `## Page N`
     * headers {@see \App\Services\Kb\Ocr\OcrService::renderMarkdown()}
     * writes between pages. Byte offsets throughout (matching `substr()` /
     * `strpos()`, not `mb_*`) — the same convention the OCR page-splitting
     * itself uses. Null when the page heading cannot be found, or
     * `$oldText` occurs zero or more than once within it.
     *
     * @return array{start: int, length: int}|null
     */
    private function locatePageOccurrence(string $markdown, int $pageNumber, string $oldText): ?array
    {
        if ($oldText === '') {
            return null;
        }
        if (preg_match_all('/^## Page (\d+)\s*$/m', $markdown, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $sectionStart = null;
        $sectionEnd = strlen($markdown);
        foreach ($matches[1] as $i => $numberMatch) {
            if ((int) $numberMatch[0] !== $pageNumber) {
                continue;
            }
            [$headerText, $headerOffset] = $matches[0][$i];
            $sectionStart = $headerOffset + strlen($headerText);
            if (isset($matches[0][$i + 1])) {
                $sectionEnd = $matches[0][$i + 1][1];
            }
            break;
        }

        if ($sectionStart === null) {
            return null;
        }

        $section = substr($markdown, $sectionStart, $sectionEnd - $sectionStart);

        $firstPos = strpos($section, $oldText);
        if ($firstPos === false) {
            return null;
        }
        $secondPos = strpos($section, $oldText, $firstPos + 1);
        if ($secondPos !== false) {
            return null;
        }

        return ['start' => $sectionStart + $firstPos, 'length' => strlen($oldText)];
    }

    /**
     * R14: confirm it IS an integrity/unique constraint violation via
     * SQLSTATE before inspecting the message (mirrors
     * DocumentVersionService::isCanonicalIdentityConflict()'s reasoning).
     * SQLSTATE 23505 = Postgres unique; 23000 = MySQL/SQLite integrity.
     */
    private function isIdempotencyKeyConflict(QueryException $e): bool
    {
        if (! in_array($e->errorInfo[0] ?? '', ['23000', '23505'], true)) {
            return false;
        }

        return str_contains($e->getMessage(), 'uq_kb_correction_candidates_idempotency_key')
            || str_contains($e->getMessage(), 'kb_text_correction_candidates.idempotency_key');
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
