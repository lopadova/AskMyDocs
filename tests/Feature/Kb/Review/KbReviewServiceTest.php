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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.37/W3 (ADR 0031 §2/§4) — KbReviewService: per-page review progress and
 * document approval (the auto -> human transition, branched on canonicity).
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

    private function user(): User
    {
        return User::create([
            'name' => 'Reviewer',
            'email' => 'reviewer-'.uniqid().'@t.local',
            'password' => Hash::make('x'),
        ]);
    }

    // --- R43 OFF path ---------------------------------------------------

    public function test_mark_page_reviewed_throws_when_disabled(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->doc();

        $this->expectException(KbReviewDisabledException::class);
        $this->svc->markPageReviewed($doc, 1, 'user:1');
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
        // service's MUTATING methods, not a pure report).
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
    public function test_mark_page_reviewed_rejects_a_non_positive_page_number(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->markPageReviewed($doc, 0, 'user:1');
    }

    public function test_mark_page_reviewed_upserts_on_the_unique_key(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();
        $reviewer1 = $this->user();
        $reviewer2 = $this->user();

        $first = $this->svc->markPageReviewed($doc, 1, "user:{$reviewer1->id}");
        $second = $this->svc->markPageReviewed($doc, 1, "user:{$reviewer2->id}");

        $this->assertSame($first->id, $second->id, 're-marking the same page must upsert, never create a second row');
        $this->assertSame(1, KbDocumentPageReview::where('knowledge_document_id', $doc->id)->count());
        $second->refresh();
        $this->assertSame(KbDocumentPageReview::STATUS_REVIEWED, $second->status);
        $this->assertSame((int) $reviewer2->id, $second->reviewed_by);
    }

    public function test_mark_page_reviewed_with_a_non_user_actor_leaves_reviewed_by_null(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();

        $review = $this->svc->markPageReviewed($doc, 1, 'cli:kb:review');

        $this->assertNull($review->reviewed_by);
    }

    public function test_document_review_summary_counts_by_status(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();
        $reviewer = $this->user();
        $this->svc->markPageReviewed($doc, 1, "user:{$reviewer->id}");
        KbDocumentPageReview::create([
            'tenant_id' => 'default',
            'knowledge_document_id' => $doc->id,
            'page_number' => 2,
            'status' => KbDocumentPageReview::STATUS_UNREVIEWED,
        ]);

        $summary = $this->svc->documentReviewSummary($doc);

        $this->assertSame(['total' => 2, 'reviewed' => 1, 'unreviewed' => 1], $summary);
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
    }
}
