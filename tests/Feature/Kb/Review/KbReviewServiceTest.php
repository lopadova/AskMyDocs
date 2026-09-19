<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Review;

use App\Exceptions\KbReviewDisabledException;
use App\Models\KbDocumentPageReview;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\Review\KbReviewService;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.37/W3 (ADR 0031 §2/§4/§9) — KbReviewService: per-page review progress
 * and document approval (the auto -> human transition, branched on
 * canonicity).
 *
 * R43 — every mutating method is exercised in BOTH states of
 * kb.review.enabled: OFF throws KbReviewDisabledException (never a silent
 * no-op), ON performs the write.
 */
final class KbReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private KbReviewService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(KbReviewService::class);
        app(TenantContext::class)->set('default');
    }

    /** @param array<string,mixed> $over */
    private function doc(array $over = []): KnowledgeDocument
    {
        return KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'eng',
            'source_type' => 'image',
            'source_path' => 'scans/contract-'.bin2hex(random_bytes(4)).'.pdf',
            'title' => 'Scanned contract',
            'mime_type' => 'application/pdf',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => bin2hex(random_bytes(16)),
            'is_canonical' => false,
            'generation_source' => GenerationSource::Auto->value,
        ], $over));
    }

    /** A converted document with a recorded page count — the precondition
     *  {@see KbReviewService::setPageReviewStatus()} now requires. */
    private function convertedDoc(int $pageCount = 5, array $over = []): KnowledgeDocument
    {
        return $this->doc(array_merge([
            'metadata' => ['converter' => ['page_count' => $pageCount]],
        ], $over));
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Reviewer',
            'email' => 'reviewer-'.uniqid().'@t.local',
            'password' => Hash::make('x'),
        ]);
    }

    // --- R43 OFF path ---------------------------------------------------

    public function test_set_page_review_status_throws_when_disabled(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->convertedDoc();

        $this->expectException(KbReviewDisabledException::class);
        $this->svc->setPageReviewStatus($doc, 1, KbDocumentPageReview::STATUS_REVIEWED, 'user:1');
    }

    public function test_approve_throws_when_disabled(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->doc();

        $this->expectException(KbReviewDisabledException::class);
        $this->svc->approve($doc, 'user:1');
    }

    public function test_document_review_summary_does_not_require_the_flag(): void
    {
        // A read (the summary) is never gated — only mutating entry points
        // throw when disabled (ADR 0031 §1 gates the HTTP surface + the
        // service's MUTATING methods, not a pure report). This document has
        // no recorded page_count, so the summary falls back to counting
        // existing rows (none) rather than deriving a total.
        config(['kb.review.enabled' => false]);
        $doc = $this->doc();

        $summary = $this->svc->documentReviewSummary($doc);

        $this->assertSame(['total' => 0, 'reviewed' => 0, 'unreviewed' => 0], $summary);
    }

    // --- R43 ON path ------------------------------------------------------

    /**
     * Copilot PR #494 (moderate) — page_number is 1-based (ADR 0031 §2); the
     * application layer must refuse 0 and below, not just Postgres's CHECK
     * constraint (which SQLite cannot enforce after CREATE TABLE).
     */
    public function test_set_page_review_status_rejects_a_non_positive_page_number(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setPageReviewStatus($doc, 0, KbDocumentPageReview::STATUS_REVIEWED, 'user:1');
    }

    /**
     * Copilot PR #494 round 4 — a page number beyond the document's own
     * recorded page count must be refused, not silently upserted as a
     * "phantom" reviewed page.
     */
    public function test_set_page_review_status_rejects_a_page_number_beyond_the_documents_page_count(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc(pageCount: 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setPageReviewStatus($doc, 999, KbDocumentPageReview::STATUS_REVIEWED, 'user:1');
    }

    /**
     * Copilot PR #494 round 4 — a document that was never converted (no
     * metadata.converter.page_count at all) must also be refused: there is
     * no page-level structure to review yet.
     */
    public function test_set_page_review_status_rejects_a_document_with_no_recorded_page_count(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc(); // no metadata.converter.page_count

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setPageReviewStatus($doc, 1, KbDocumentPageReview::STATUS_REVIEWED, 'user:1');
    }

    public function test_set_page_review_status_rejects_an_unknown_status_value(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setPageReviewStatus($doc, 1, 'bogus', 'user:1');
    }

    public function test_set_page_review_status_upserts_on_the_unique_key(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc();
        $reviewer1 = $this->user();
        $reviewer2 = $this->user();

        $first = $this->svc->setPageReviewStatus($doc, 1, KbDocumentPageReview::STATUS_REVIEWED, "user:{$reviewer1->id}");
        $second = $this->svc->setPageReviewStatus($doc, 1, KbDocumentPageReview::STATUS_REVIEWED, "user:{$reviewer2->id}");

        $this->assertSame($first->id, $second->id, 're-marking the same page must upsert, never create a second row');
        $this->assertSame(1, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count());
        $second->refresh();
        $this->assertSame(KbDocumentPageReview::STATUS_REVIEWED, $second->status);
        $this->assertSame((int) $reviewer2->id, $second->reviewed_by);
    }

    /**
     * ADR 0031 §9's `--status=` contract: a page can be legitimately
     * reverted from reviewed back to unreviewed (a reviewer un-marks a page
     * clicked by mistake) — clearing reviewed_by/reviewed_at, not just
     * flipping a boolean.
     */
    public function test_set_page_review_status_can_revert_a_page_to_unreviewed(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc();
        $reviewer = $this->user();
        $this->svc->setPageReviewStatus($doc, 1, KbDocumentPageReview::STATUS_REVIEWED, "user:{$reviewer->id}");

        $reverted = $this->svc->setPageReviewStatus($doc, 1, KbDocumentPageReview::STATUS_UNREVIEWED, "user:{$reviewer->id}");

        $this->assertSame(KbDocumentPageReview::STATUS_UNREVIEWED, $reverted->status);
        $this->assertNull($reverted->reviewed_by, 'reverting to unreviewed must clear reviewed_by');
        $this->assertNull($reverted->reviewed_at, 'reverting to unreviewed must clear reviewed_at');
        $this->assertSame(1, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count());
    }

    public function test_set_page_review_status_with_a_non_user_actor_leaves_reviewed_by_null(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc();

        $review = $this->svc->setPageReviewStatus($doc, 1, KbDocumentPageReview::STATUS_REVIEWED, 'cli:kb:review');

        $this->assertNull($review->reviewed_by);
    }

    public function test_document_review_summary_counts_by_status(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc(pageCount: 2);
        $reviewer = $this->user();
        $this->svc->setPageReviewStatus($doc, 1, KbDocumentPageReview::STATUS_REVIEWED, "user:{$reviewer->id}");

        $summary = $this->svc->documentReviewSummary($doc);

        $this->assertSame(['total' => 2, 'reviewed' => 1, 'unreviewed' => 1], $summary);
    }

    /**
     * Copilot PR #494 round 4 (must-fix) — the total must reflect the
     * document's REAL page count as soon as it is known, even before any
     * page has been touched. Pre-fix, reviewing page 1 of a 10-page
     * document reported total=1 (the one row that existed); this proves the
     * total is correct on page 0-of-N reviewed too, not only after a page
     * has a row.
     */
    public function test_document_review_summary_derives_total_from_page_count_before_any_page_is_reviewed(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc(pageCount: 10);

        $summary = $this->svc->documentReviewSummary($doc);

        $this->assertSame(['total' => 10, 'reviewed' => 0, 'unreviewed' => 10], $summary);
    }

    /**
     * Copilot PR #494 round 5 (must-fix) — when a correction re-chunks a
     * document into FEWER pages, the reviewed-row query must exclude rows
     * for pages that no longer exist. Pre-fix, `min($reviewedCount,
     * $pageCount)` only clamped the TOTAL after counting every reviewed
     * row unconditionally — so page 10's now-obsolete reviewed row
     * survived a re-chunk down to 2 pages and was misattributed onto a
     * document whose 2 real pages were never touched, reporting
     * `reviewed: 1` instead of `reviewed: 0`.
     */
    public function test_document_review_summary_excludes_reviewed_rows_beyond_a_reduced_page_count(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc(pageCount: 10);
        $reviewer = $this->user();
        $this->svc->setPageReviewStatus($doc, 10, KbDocumentPageReview::STATUS_REVIEWED, "user:{$reviewer->id}");

        // A correction re-chunks the document down to 2 pages — page 10's
        // review row is now obsolete, but it is never deleted; the fix
        // excludes it from the COUNT, not the row itself.
        $doc->forceFill(['metadata' => ['converter' => ['page_count' => 2]]])->save();
        $this->assertDatabaseHas('kb_document_page_reviews', [
            'knowledge_document_id' => $doc->id,
            'page_number' => 10,
            'status' => KbDocumentPageReview::STATUS_REVIEWED,
        ]);

        $summary = $this->svc->documentReviewSummary($doc);

        $this->assertSame(['total' => 2, 'reviewed' => 0, 'unreviewed' => 2], $summary);
    }

    public function test_page_review_status_reports_unreviewed_for_a_never_touched_page(): void
    {
        $doc = $this->convertedDoc(pageCount: 3);

        $status = $this->svc->pageReviewStatus($doc, 2);

        $this->assertSame(2, $status['page_number']);
        $this->assertSame(KbDocumentPageReview::STATUS_UNREVIEWED, $status['status']);
        $this->assertNull($status['reviewed_by']);
        $this->assertNull($status['reviewed_at']);
    }

    public function test_page_review_status_reflects_a_reviewed_page(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc(pageCount: 3);
        $reviewer = $this->user();
        $this->svc->setPageReviewStatus($doc, 2, KbDocumentPageReview::STATUS_REVIEWED, "user:{$reviewer->id}");

        $status = $this->svc->pageReviewStatus($doc, 2);

        $this->assertSame(KbDocumentPageReview::STATUS_REVIEWED, $status['status']);
        $this->assertSame((int) $reviewer->id, $status['reviewed_by']);
        $this->assertNotNull($status['reviewed_at']);
    }

    public function test_page_review_status_rejects_a_page_beyond_the_documents_page_count(): void
    {
        $doc = $this->convertedDoc(pageCount: 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->pageReviewStatus($doc, 999);
    }

    /**
     * Copilot PR #494 round 5 (must-fix) — a document with no recorded
     * page_count (never converted) used to skip the upper-bound check
     * entirely, so `pageReviewStatus($doc, 999)` returned 200 `unreviewed`
     * for a page number with no basis to exist — even though
     * `setPageReviewStatus()` already refuses the SAME document on the
     * write side. The read side must refuse it too, for any page number,
     * including 1 — there is no "safe" page to report on an unconverted
     * document.
     */
    public function test_page_review_status_rejects_a_document_with_no_recorded_page_count(): void
    {
        $doc = $this->doc(); // no metadata.converter.page_count

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->pageReviewStatus($doc, 1);
    }

    public function test_approve_on_a_non_canonical_auto_document_flips_generation_source_and_audits(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->doc(['is_canonical' => false, 'generation_source' => GenerationSource::Auto->value]);

        $result = $this->svc->approve($doc, 'user:1');

        $this->assertTrue($result['approved']);
        $doc->refresh();
        $this->assertSame(GenerationSource::Human->value, $doc->generation_source);
        // The non-canonical branch must NEVER touch canonical_status — it is
        // a canonical-only column (ADR 0031 §4); asserting it stays null
        // proves KbReviewService did not reuse WikiExplorerService::promote()
        // for this branch.
        $this->assertNull($doc->canonical_status);
        $this->assertDatabaseHas('kb_canonical_audit', [
            'tenant_id' => 'default',
            'doc_id' => $doc->doc_id,
            'event_type' => 'promoted',
            'actor' => 'user:1',
        ]);
    }

    public function test_approve_on_a_canonical_document_delegates_to_wiki_explorer_promote(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->doc([
            'is_canonical' => true,
            'doc_id' => 'dec-x',
            'slug' => 'dec-x',
            'canonical_type' => 'decision',
            'canonical_status' => 'review',
            'generation_source' => GenerationSource::Auto->value,
        ]);

        $result = $this->svc->approve($doc, 'user:1');

        $this->assertTrue($result['approved']);
        $doc->refresh();
        $this->assertSame(GenerationSource::Human->value, $doc->generation_source);
        // The canonical branch DOES set canonical_status — that is
        // WikiExplorerService::promote()'s own contract, correctly reused
        // here rather than duplicated.
        $this->assertSame('accepted', $doc->canonical_status);
    }

    public function test_approve_on_an_already_human_document_is_a_safe_no_op(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc(['is_canonical' => false, 'generation_source' => GenerationSource::Human->value]);

        $result = $this->svc->approve($doc, 'user:1');

        $this->assertFalse($result['approved']);
        $this->assertSame('not_auto', $result['reason']);
        // Copilot PR #494 round 2 — the return flags alone don't prove
        // nothing was written; a bug could mutate the row or write an
        // audit entry and this test would still pass on the flags. Assert
        // the row is genuinely untouched and no audit row exists.
        $doc->refresh();
        $this->assertSame(GenerationSource::Human->value, $doc->generation_source);
        $this->assertDatabaseCount('kb_canonical_audit', 0);
    }

    /**
     * R21 (Copilot PR #494 round 2) — approve() now locks the document row
     * INSIDE its transaction and re-reads is_canonical/generation_source
     * from that locked row rather than the CALLER'S copy. SQLite cannot
     * enforce real blocking on `lockForUpdate()` (same limitation
     * documented on KbDocumentVersionControllerTest's races: `DB::listen`
     * fires AFTER a query executes, not before, so it cannot intercept a
     * read mid-flight either), so a true two-connection interleaving isn't
     * stageable here. What IS directly testable — and is exactly the bug
     * shape this fix closes — is that the decision comes from a FRESH read
     * of the row, not from the `KnowledgeDocument` INSTANCE the caller
     * happens to be holding: simulate "a concurrent worker already
     * committed the approval" by updating the DB directly (bypassing the
     * `$doc` instance in memory, which still reports the OLD generation_
     * source) and writing that winner's audit row, THEN call approve()
     * with the now-stale `$doc` instance. Pre-fix, the method decided from
     * `$document->generation_source` (the stale in-memory 'auto') and
     * would flip the row AGAIN, producing a second audit row. Post-fix, it
     * re-queries inside the transaction, sees the DB's 'human', and
     * returns the safe not_auto no-op — exactly one audit row survives.
     */
    public function test_approve_decides_from_a_fresh_read_not_the_callers_stale_document_instance(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->doc(['is_canonical' => false, 'generation_source' => GenerationSource::Auto->value]);

        // A concurrent worker's approve() call that already committed,
        // bypassing the in-memory $doc instance entirely.
        \Illuminate\Support\Facades\DB::table('knowledge_documents')
            ->where('id', $doc->id)
            ->update(['generation_source' => GenerationSource::Human->value]);
        \App\Models\KbCanonicalAudit::create([
            'tenant_id' => 'default',
            'project_key' => (string) $doc->project_key,
            'doc_id' => $doc->doc_id,
            'slug' => $doc->slug,
            'event_type' => 'promoted',
            'actor' => 'user:2',
            'before_json' => ['generation_source' => 'auto'],
            'after_json' => ['generation_source' => 'human'],
            'metadata_json' => ['source' => 'concurrent_winner'],
        ]);

        $this->assertSame(
            GenerationSource::Auto->value,
            $doc->generation_source,
            'the in-memory $doc instance must still report the stale value — the concurrent write bypassed it',
        );

        $result = $this->svc->approve($doc, 'user:1');

        $this->assertFalse($result['approved'], 'a fresh read must see the concurrent winner\'s human state');
        $this->assertSame('not_auto', $result['reason']);
        // exactly the concurrent winner's audit row must exist, never a second one
        $this->assertDatabaseCount('kb_canonical_audit', 1);
    }

    /**
     * Copilot PR #494 round 4 (must-fix) — `save()` returns `false` when a
     * model event vetoes the write. Before this fix, the ignored return
     * value meant a vetoed save still fell through to writing the
     * 'promoted' audit row while generation_source stayed 'auto' on disk —
     * an approval that is audited but never actually happened. A `saving`
     * listener scoped to THIS document's id simulates the veto; the whole
     * transaction (row lock included) must roll back, so neither the flip
     * nor its audit row survives.
     */
    public function test_approve_throws_and_writes_nothing_when_the_generation_source_save_is_vetoed(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->doc(['is_canonical' => false, 'generation_source' => GenerationSource::Auto->value]);

        KnowledgeDocument::saving(fn (KnowledgeDocument $model): bool => $model->getKey() !== $doc->id);

        $thrown = null;

        try {
            try {
                $this->svc->approve($doc, 'user:1');
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
        } finally {
            Event::forget('eloquent.saving: '.KnowledgeDocument::class);
        }

        $this->assertNotNull($thrown, 'a vetoed save must surface as a thrown exception, not a silent approved:true');
        $doc->refresh();
        $this->assertSame(GenerationSource::Auto->value, $doc->generation_source, 'a vetoed save must leave generation_source untouched');
        // a vetoed save must never be followed by an audit row (fail-closed)
        $this->assertDatabaseCount('kb_canonical_audit', 0);
    }
}
