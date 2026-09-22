<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\DigitizationReview;

use App\Models\KbDocumentPageReview;
use App\Models\KnowledgeDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.37/W3 (ADR 0031 §2) — schema-level regression coverage for the
 * per-page review table, independent of {@see \App\Services\Kb\Review\KbReviewService}
 * (covered separately by KbReviewServiceTest): this file exercises the raw
 * Eloquent model + migration directly. Proves: (a) the tenant-scoped unique
 * key (tenant_id, knowledge_document_id, page_number) really rejects a
 * duplicate row rather than silently accumulating one review per save,
 * (b) the FK cascade-deletes review rows with their document (R30/R31),
 * and (c) the reviewed/unreviewed scopes filter correctly.
 */
final class KbDocumentPageReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeDoc(string $path = 'docs/scan.md'): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'Scanned page',
            'source_path' => $path,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => null,
        ]);
    }

    public function test_unique_key_rejects_a_duplicate_tenant_document_page_row(): void
    {
        $doc = $this->makeDoc();

        KbDocumentPageReview::create([
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'status' => KbDocumentPageReview::STATUS_UNREVIEWED,
        ]);

        $this->expectException(QueryException::class);

        KbDocumentPageReview::create([
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'status' => KbDocumentPageReview::STATUS_UNREVIEWED,
        ]);
    }

    public function test_a_different_page_number_on_the_same_document_is_a_distinct_row(): void
    {
        $doc = $this->makeDoc();

        KbDocumentPageReview::create([
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'status' => KbDocumentPageReview::STATUS_UNREVIEWED,
        ]);
        KbDocumentPageReview::create([
            'knowledge_document_id' => $doc->id,
            'page_number' => 2,
            'status' => KbDocumentPageReview::STATUS_UNREVIEWED,
        ]);

        $this->assertSame(2, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count());
    }

    public function test_deleting_the_document_cascades_its_page_reviews(): void
    {
        $doc = $this->makeDoc();
        $review = KbDocumentPageReview::create([
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'status' => KbDocumentPageReview::STATUS_UNREVIEWED,
        ]);

        $doc->forceDelete();

        $this->assertDatabaseMissing('kb_document_page_reviews', ['id' => $review->id]);
    }

    public function test_reviewed_and_unreviewed_scopes_filter_by_status(): void
    {
        $doc = $this->makeDoc();
        KbDocumentPageReview::create([
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'status' => KbDocumentPageReview::STATUS_REVIEWED,
        ]);
        KbDocumentPageReview::create([
            'knowledge_document_id' => $doc->id,
            'page_number' => 2,
            'status' => KbDocumentPageReview::STATUS_UNREVIEWED,
        ]);

        $this->assertSame(1, KbDocumentPageReview::reviewed()->count());
        $this->assertSame(1, KbDocumentPageReview::unreviewed()->count());
        $this->assertSame(1, KbDocumentPageReview::reviewed()->first()->page_number);
        $this->assertSame(2, KbDocumentPageReview::unreviewed()->first()->page_number);
    }
}
