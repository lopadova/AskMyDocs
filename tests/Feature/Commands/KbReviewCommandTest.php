<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\KbDocumentPageReview;
use App\Models\KnowledgeDocument;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.37/W3 (ADR 0031, R44 PHP/CLI surface) — `kb:review` mirrors
 * KbReviewService: set a page's review status, approve a document, report
 * its review summary.
 */
final class KbReviewCommandTest extends TestCase
{
    use RefreshDatabase;

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
     *  KbReviewService::setPageReviewStatus() now requires. */
    private function convertedDoc(int $pageCount = 5, array $over = []): KnowledgeDocument
    {
        return $this->doc(array_merge([
            'metadata' => ['converter' => ['page_count' => $pageCount]],
        ], $over));
    }

    public function test_sets_a_page_to_reviewed_by_default(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc();

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 1])
            ->expectsOutputToContain("Page 1 set to 'reviewed'.")
            ->assertExitCode(0);

        $this->assertDatabaseHas('kb_document_page_reviews', [
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'status' => KbDocumentPageReview::STATUS_REVIEWED,
        ]);
    }

    /**
     * ADR 0031 §9's `--status=` contract — a page can be reverted back to
     * unreviewed, not just marked reviewed once.
     */
    public function test_status_option_can_revert_a_page_to_unreviewed(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc();
        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 1])->assertExitCode(0);

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 1, '--status' => 'unreviewed'])
            ->expectsOutputToContain("Page 1 set to 'unreviewed'.")
            ->assertExitCode(0);

        $this->assertDatabaseHas('kb_document_page_reviews', [
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'status' => KbDocumentPageReview::STATUS_UNREVIEWED,
            'reviewed_by' => null,
        ]);
    }

    public function test_status_option_rejects_an_unknown_value(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc();

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 1, '--status' => 'bogus'])
            ->assertExitCode(1);

        $this->assertSame(0, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count());
    }

    public function test_approves_a_document(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();

        $this->artisan('kb:review', ['document' => $doc->id, '--approve' => true])
            ->expectsOutputToContain('Document approved')
            ->assertExitCode(0);

        $doc->refresh();
        $this->assertSame(GenerationSource::Human->value, $doc->generation_source);
    }

    public function test_report_mode_prints_the_summary_without_mutating(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();

        $this->artisan('kb:review', ['document' => $doc->id, '--report' => true])
            ->assertExitCode(0);

        $this->assertSame(0, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count());
    }

    public function test_fails_cleanly_on_a_non_positive_page_number(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc();

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 0])
            ->assertExitCode(1);

        $this->assertSame(0, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count());
    }

    /**
     * Copilot PR #494 round 4 (must-fix) — `(int) '12.5'` silently
     * truncates to 12, so `kb:review 12.5 --approve` could act on the WRONG
     * document without any error. Both the positional document id and the
     * --page option must reject a malformed value rather than truncate it.
     */
    public function test_rejects_a_non_integer_document_argument_rather_than_truncating(): void
    {
        config(['kb.review.enabled' => true]);

        $this->artisan('kb:review', ['document' => '12.5'])
            ->expectsOutputToContain('document must be a positive integer')
            ->assertExitCode(1);
    }

    public function test_rejects_a_non_integer_page_option_rather_than_truncating(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc();

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => '1.5'])
            ->expectsOutputToContain('--page must be a positive integer')
            ->assertExitCode(1);

        $this->assertSame(0, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count());
    }

    /**
     * Copilot PR #494 round 4 — a page number beyond the document's own
     * recorded page count is a defined failure, not a silently-created
     * phantom row.
     */
    /**
     * Copilot PR #494 round 5 (must-fix) — `--page=N --report` is the CLI's
     * read of ADR 0031 §9's per-page contract: it prints page N's status
     * and mutates nothing. Before this fix the combo did not exist — every
     * `--page` invocation always mutated, contradicting the documented
     * contract table.
     */
    public function test_page_and_report_together_read_a_page_without_mutating(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc(pageCount: 3);

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 2, '--report' => true])
            ->assertExitCode(0);

        $this->assertSame(0, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count(), '--page + --report must never create a row');
    }

    /**
     * The `--page --report` read must reflect an already-reviewed page's
     * real status, not just prove it does not mutate.
     */
    public function test_page_and_report_together_reflect_a_reviewed_pages_status(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc(pageCount: 3);
        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 2])->assertExitCode(0);

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 2, '--report' => true])
            ->expectsOutputToContain('reviewed')
            ->assertExitCode(0);
    }

    /**
     * Copilot PR #494 round 5 (must-fix) — the report display (doc-wide
     * AND per-page) must be gated behind kb.review.enabled, mirroring the
     * HTTP surface (which explicitly 404s a read when disabled). Before
     * this fix, only the MUTATING options threw when disabled — a report
     * (a read) printed happily on a deployment where the HTTP contract
     * says the same read does not exist.
     */
    public function test_report_mode_is_gated_behind_the_disabled_flag(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->doc();

        $this->artisan('kb:review', ['document' => $doc->id, '--report' => true])
            ->expectsOutputToContain('Digitization Review is disabled')
            ->assertExitCode(1);
    }

    /** Same gate, for the `--page --report` combo specifically. */
    public function test_page_report_combo_is_gated_behind_the_disabled_flag(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->convertedDoc(pageCount: 3);

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 1, '--report' => true])
            ->expectsOutputToContain('Digitization Review is disabled')
            ->assertExitCode(1);
    }

    /**
     * Copilot PR #494 round 6 (must-fix) — `--report` is documented as
     * read-only, but `--report --approve` (no --page) used to still run
     * the approval and only then print the report: a "read-only" flag that
     * mutated. Reject the combination outright, before any document is
     * even fetched successfully — approve() must never fire.
     */
    public function test_report_and_approve_together_are_rejected(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc(['generation_source' => GenerationSource::Auto->value]);

        $this->artisan('kb:review', ['document' => $doc->id, '--report' => true, '--approve' => true])
            ->expectsOutputToContain('--report cannot be combined with --approve')
            ->assertExitCode(1);

        $doc->refresh();
        $this->assertSame(GenerationSource::Auto->value, $doc->generation_source, 'the rejected combo must never approve the document');
    }

    /**
     * Same rejection with `--page` present too — before this fix, THIS
     * exact combo took the opposite path from the one above (it returned
     * early from the page-read branch and silently skipped --approve
     * instead of running it) — two different mutation outcomes for what
     * users would reasonably expect to be the same "read-only" flag.
     */
    public function test_page_report_and_approve_together_are_rejected(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc(pageCount: 3, over: ['generation_source' => GenerationSource::Auto->value]);

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 1, '--report' => true, '--approve' => true])
            ->expectsOutputToContain('--report cannot be combined with --approve')
            ->assertExitCode(1);

        $doc->refresh();
        $this->assertSame(GenerationSource::Auto->value, $doc->generation_source, 'the rejected combo must never approve the document');
        $this->assertSame(0, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count(), 'the rejected combo must never write a page review row');
    }

    public function test_fails_cleanly_when_the_page_exceeds_the_documents_page_count(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->convertedDoc(pageCount: 1);

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 999])
            ->assertExitCode(1);

        $this->assertSame(0, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count());
    }

    public function test_fails_cleanly_on_an_unknown_document(): void
    {
        $this->artisan('kb:review', ['document' => 999999])
            ->expectsOutputToContain('Document not found')
            ->assertExitCode(1);
    }

    public function test_surfaces_the_disabled_flag_rather_than_crashing(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->convertedDoc();

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 1])
            // Copilot PR #494 round 2 — exit code 1 alone doesn't prove
            // WHICH error fired; assert the actual disabled message so an
            // unrelated failure (e.g. a DB error) cannot pass this test.
            ->expectsOutputToContain('Digitization Review is disabled')
            ->assertExitCode(1);
    }

    /**
     * Copilot PR #494 round 2 — `TenantContext` is a process-wide
     * singleton (`KbOcrCommand` established the restore pattern this
     * command now follows). Without restoring it, a command run with
     * `--tenant=other` would leave a SUBSEQUENT tenant-aware operation in
     * the same Artisan/test process pinned to `other` instead of whatever
     * the caller had set. Assert the context is back to the pre-call
     * tenant after the command returns, on both the success path and the
     * early "document not found" return.
     */
    public function test_restores_the_previous_tenant_context_after_running(): void
    {
        // Copilot PR #494 round 5 — the report display is now gated behind
        // kb.review.enabled (mirroring the HTTP surface); this test's
        // concern is tenant-context restoration, not the gate itself, so
        // enable it here rather than let an unrelated default trip it.
        config(['kb.review.enabled' => true]);
        $tenants = app(TenantContext::class);
        $tenants->set('pre-existing-tenant');
        $doc = $this->doc(['tenant_id' => 'other-tenant']);

        $this->artisan('kb:review', ['document' => $doc->id, '--report' => true, '--tenant' => 'other-tenant'])
            ->assertExitCode(0);

        $this->assertSame('pre-existing-tenant', $tenants->current());

        // Also on the early not-found return path.
        $this->artisan('kb:review', ['document' => 999999, '--tenant' => 'other-tenant'])
            ->assertExitCode(1);

        $this->assertSame('pre-existing-tenant', $tenants->current());
    }

    /**
     * The document-argument validation (Copilot round 4) happens BEFORE
     * the tenant is switched — confirm it does not leak a tenant switch
     * either, on a document id that fails validation outright.
     */
    public function test_restores_the_previous_tenant_context_when_the_document_argument_is_invalid(): void
    {
        $tenants = app(TenantContext::class);
        $tenants->set('pre-existing-tenant');

        $this->artisan('kb:review', ['document' => '12.5', '--tenant' => 'other-tenant'])
            ->assertExitCode(1);

        $this->assertSame('pre-existing-tenant', $tenants->current());
    }
}
