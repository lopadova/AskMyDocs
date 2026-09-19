<?php

declare(strict_types=1);

namespace App\Services\Kb\Review;

use App\Exceptions\KbReviewDisabledException;
use App\Exceptions\KbReviewRateLimitedException;
use App\Jobs\IngestDocumentJob;
use App\Models\KbCanonicalAudit;
use App\Models\KbDocumentPageReview;
use App\Models\KbTextCorrectionCandidate;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiExplorerService;
use App\Services\Kb\Versioning\DocumentVersionService;
use App\Support\Canonical\GenerationSource;
use App\Support\Kb\StorageNamespace;
use App\Support\KbDiskResolver;
use App\Support\KbPath;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

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
     * touched — a replay never spends the actor's budget. A genuinely
     * concurrent double-call is resolved by the `UNIQUE` constraint to
     * exactly one row: the loser's insert fails and re-reads the winner's.
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

        if ($pageNumber < 1) {
            throw new \InvalidArgumentException("page_number must be >= 1, got {$pageNumber}.");
        }
        if (trim($oldText) === '') {
            throw new \InvalidArgumentException('old_text must not be empty.');
        }
        if (mb_strlen($newText) > 4000) {
            throw new \InvalidArgumentException('new_text must be at most 4000 characters.');
        }
        if ($rationale !== null && mb_strlen($rationale) > 500) {
            throw new \InvalidArgumentException('rationale must be at most 500 characters.');
        }

        $tenantId = (string) $document->tenant_id;
        $live = $this->versions->currentVersionFor($document);
        $content = $this->versions->contentFor($live);

        $located = $this->locatePageOccurrence((string) $content['content'], $pageNumber, $oldText);
        if ($located === null) {
            throw new \InvalidArgumentException("old_text does not occur exactly once on page {$pageNumber} of document {$live->id}.");
        }

        $versionHash = (string) $live->version_hash;
        $key = KbTextCorrectionCandidate::idempotencyKeyFor($tenantId, $actor, (int) $live->id, $versionHash, $pageNumber, $oldText, $newText);

        // forTenant() here is defense-in-depth, not the correctness
        // mechanism: idempotency_key already hashes tenantId in, so a
        // cross-tenant collision is cryptographically infeasible — but
        // every query against a tenant-aware table funnels through
        // forTenant() regardless (R30).
        $existing = KbTextCorrectionCandidate::query()->forTenant($tenantId)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        $limit = max(0, (int) config('kb.review.candidates_per_hour', 60));
        $limiterKey = "kb-review-candidates:{$tenantId}:{$actor}";
        if ($limit > 0 && RateLimiter::tooManyAttempts($limiterKey, $limit)) {
            throw new KbReviewRateLimitedException($actor, $limit);
        }
        if ($limit > 0) {
            RateLimiter::hit($limiterKey, 3600);
        }

        try {
            return KbTextCorrectionCandidate::create([
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
        } catch (QueryException $e) {
            if (! $this->isIdempotencyKeyConflict($e)) {
                throw $e;
            }
            // A concurrent proposer won the race on the SAME idempotency_key
            // between our SELECT above and this INSERT — the loser re-reads
            // the winner's row rather than surfacing a uniqueness violation
            // (ADR 0031 §6: "resolved by the unique constraint to exactly
            // one row").
            return KbTextCorrectionCandidate::query()->forTenant($tenantId)->where('idempotency_key', $key)->firstOrFail();
        }
    }

    /**
     * ADR 0031 §6 — the reviewer's approval, single-use and atomic (R21).
     * ONE transaction: `lockForUpdate()`s the candidate row (refusing any
     * row whose status is no longer `pending`) TOGETHER WITH the document
     * row, re-validates `old_text` still occurs exactly once on the CURRENT
     * live version's page (a correction proposed against version N must not
     * silently apply to version N+1's different text — a stale mismatch
     * REJECTS the candidate rather than leaving it pending forever), applies
     * it by writing the corrected Markdown to the same disk path the family
     * already lives at and queuing `IngestDocumentJob` (the identical
     * re-ingest path `KbDocumentController::updateRaw()` takes — CLAUDE.md
     * §6's single ingestion execution path), writes the `kb_canonical_audit`
     * row, and marks the candidate `applied` + `consumed_at`. Two reviewers
     * approving the SAME candidate concurrently: the second transaction's
     * `lockForUpdate()` blocks until the first commits, then sees
     * `status != 'pending'` and returns `{applied: false, reason:
     * 'already_consumed'}` — never a duplicate version, never a silent
     * second no-op.
     *
     * The Markdown write to disk happens INSIDE this transaction but is not
     * itself transactional (no filesystem two-phase commit exists) — a
     * throw AFTER a successful write but before the transaction commits
     * leaves the corrected bytes on disk while the candidate stays
     * `pending`. The write is idempotent (retrying reproduces the same
     * bytes), so a retried approval self-heals; this is the same residual
     * risk `KbDocumentController::updateRaw()` already accepts for its own
     * disk-write-then-audit-then-job sequence.
     *
     * @return array{applied: bool, reason?: string}
     */
    public function approveCorrection(KbTextCorrectionCandidate $candidate, string $actor, ?int $reviewerUserId): array
    {
        $this->assertEnabled();

        $tenantId = (string) $candidate->tenant_id;
        $candidateId = (int) $candidate->id;

        return DB::transaction(function () use ($tenantId, $candidateId, $actor, $reviewerUserId): array {
            /** @var KbTextCorrectionCandidate $locked */
            $locked = KbTextCorrectionCandidate::query()
                ->forTenant($tenantId)
                ->lockForUpdate()
                ->findOrFail($candidateId);

            if ($locked->status !== KbTextCorrectionCandidate::STATUS_PENDING) {
                return ['applied' => false, 'reason' => 'already_consumed'];
            }

            /** @var KnowledgeDocument|null $document */
            $document = KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->lockForUpdate()
                ->find($locked->knowledge_document_id);

            if ($document === null) {
                $locked->forceFill([
                    'status' => KbTextCorrectionCandidate::STATUS_REJECTED,
                    'consumed_at' => now(),
                    'consumed_by' => $reviewerUserId,
                ])->save();

                return ['applied' => false, 'reason' => 'document_not_found'];
            }

            $live = $this->versions->currentVersionFor($document);
            $content = $this->versions->contentFor($live);
            $markdown = (string) $content['content'];

            $located = $this->locatePageOccurrence($markdown, (int) $locked->page_number, (string) $locked->old_text);
            if ($located === null) {
                $locked->forceFill([
                    'status' => KbTextCorrectionCandidate::STATUS_REJECTED,
                    'consumed_at' => now(),
                    'consumed_by' => $reviewerUserId,
                ])->save();

                return ['applied' => false, 'reason' => 'stale_old_text_not_found_or_ambiguous'];
            }

            $corrected = substr($markdown, 0, $located['start']).$locked->new_text.substr($markdown, $located['start'] + $located['length']);

            $sourcePath = KbPath::normalize((string) $live->source_path);
            $metadata = is_array($live->metadata) ? $live->metadata : [];
            $disk = StorageNamespace::recordedDisk($metadata) ?? KbDiskResolver::forProject($live->project_key);
            $prefix = trim(StorageNamespace::recordedPrefix($metadata), '/');
            $fullPath = $prefix === '' ? $sourcePath : $prefix.'/'.$sourcePath;

            if (Storage::disk($disk)->put($fullPath, $corrected) === false) {
                // R4/R14: a load-bearing write failure must not proceed to
                // audit + dispatch as though the correction had landed.
                throw new \RuntimeException("Failed to write corrected markdown for document {$live->id} (candidate {$candidateId}) to disk {$disk}:{$fullPath}.");
            }

            if ((bool) config('kb.canonical.audit_enabled', true)) {
                KbCanonicalAudit::create([
                    'tenant_id' => $tenantId,
                    'project_key' => (string) $live->project_key,
                    'doc_id' => $live->doc_id,
                    'slug' => $live->slug,
                    'event_type' => 'updated',
                    'actor' => $actor,
                    'before_json' => ['version_hash' => $live->version_hash, 'old_text' => $locked->old_text],
                    'after_json' => ['new_text' => $locked->new_text, 'page_number' => $locked->page_number],
                    'metadata_json' => ['source' => 'kb_review_correction_candidate', 'candidate_id' => $candidateId],
                ]);
            }

            // Single ingestion execution path (CLAUDE.md §6) — the queued
            // job re-reads from this same disk+prefix combination, chunks,
            // embeds and refreshes graph edges, exactly like
            // KbDocumentController::updateRaw()'s manual-edit path.
            IngestDocumentJob::dispatchForCurrentTenant(
                projectKey: $live->project_key,
                relativePath: $sourcePath,
                disk: $disk,
                title: $live->title,
                metadata: $metadata,
            );

            $locked->forceFill([
                'status' => KbTextCorrectionCandidate::STATUS_APPLIED,
                'consumed_at' => now(),
                'consumed_by' => $reviewerUserId,
            ])->save();

            return ['applied' => true];
        });
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
