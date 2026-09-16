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
 * KbReviewService: mark a page reviewed, approve a document, report its
 * review summary.
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

    public function test_marks_a_page_reviewed(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 1])
            ->expectsOutputToContain('Page 1 marked reviewed.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('kb_document_page_reviews', [
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'status' => KbDocumentPageReview::STATUS_REVIEWED,
        ]);
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
        $doc = $this->doc();

        $this->artisan('kb:review', ['document' => $doc->id, '--page' => 0])
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
        $doc = $this->doc();

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
}
