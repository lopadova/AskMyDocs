<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\KbDocumentPageReview;
use App\Models\KnowledgeDocument;
use App\Support\Canonical\GenerationSource;
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
            ->assertExitCode(1);
    }
}
