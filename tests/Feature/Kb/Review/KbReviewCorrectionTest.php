<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Review;

use App\Exceptions\KbReviewDisabledException;
use App\Exceptions\KbReviewRateLimitedException;
use App\Jobs\IngestDocumentJob;
use App\Models\KbCanonicalAudit;
use App\Models\KbTextCorrectionCandidate;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\Review\KbReviewService;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.37/W3b (ADR 0031 §6-7) — KbReviewService::proposeCorrection() /
 * approveCorrection() / rejectCorrection(): the agent-proposed
 * text-correction-CANDIDATE flow. R43 — the mutating entry points are
 * exercised in both states of kb.review.enabled.
 *
 * Documents in this file carry real Markdown content readable via
 * DocumentVersionService::contentFor() through the chunk-reconstruction
 * fallback (a single chunk whose text IS the whole `## Page N`-delimited
 * document) — no artifact/Storage setup needed for the READ path;
 * approveCorrection()'s WRITE path needs Storage::fake('kb') (R4/R14,
 * mirrors KbDocumentControllerTest::test_update_raw_*).
 */
final class KbReviewCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private KbReviewService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(KbReviewService::class);
        app(TenantContext::class)->set('default');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '']);
        Storage::fake('kb');
    }

    private const PAGE_1 = "Bod is the contractor's chosen supplier for all steel deliveries.";

    private const PAGE_2 = 'This page is unrelated boilerplate text.';

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
            'metadata' => ['converter' => ['page_count' => 2]],
        ], $over));
    }

    /** A document whose content is readable via chunk-reconstruction: one
     *  chunk whose text is the whole `## Page N`-delimited document. */
    private function docWithContent(): KnowledgeDocument
    {
        $doc = $this->doc();
        $markdown = "# scan\n\n## Page 1\n\n".self::PAGE_1."\n\n## Page 2\n\n".self::PAGE_2."\n";
        KnowledgeChunk::create([
            'tenant_id' => 'default',
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $markdown),
            'heading_path' => null,
            'chunk_text' => $markdown,
            'metadata' => [],
        ]);

        return $doc;
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Reviewer',
            'email' => 'reviewer-'.uniqid().'@t.local',
            'password' => Hash::make('x'),
        ]);
    }

    // --- proposeCorrection --------------------------------------------

    public function test_propose_correction_throws_when_disabled(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->docWithContent();

        $this->expectException(KbReviewDisabledException::class);
        $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
    }

    public function test_propose_correction_creates_a_pending_candidate(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithContent();

        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', 'likely an OCR misread', 'user:1');

        $this->assertSame(KbTextCorrectionCandidate::STATUS_PENDING, $candidate->status);
        $this->assertSame(1, $candidate->page_number);
        $this->assertSame('Bod', $candidate->old_text);
        $this->assertSame('Bob', $candidate->new_text);
        $this->assertSame('likely an OCR misread', $candidate->rationale);
        $this->assertSame('user:1', $candidate->proposed_by);
        $this->assertSame($doc->id, $candidate->knowledge_document_id);
        $this->assertSame($doc->version_hash, $candidate->version_hash);
        $this->assertDatabaseCount('kb_text_correction_candidates', 1);
    }

    public function test_propose_correction_refuses_old_text_not_found_on_the_page(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithContent();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->proposeCorrection($doc, 1, 'never appears anywhere', 'x', null, 'user:1');
    }

    /**
     * ADR 0031 §6 — "ambiguous or absent -> refused, never 'first match'".
     */
    public function test_propose_correction_refuses_an_ambiguous_old_text(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();
        $markdown = "# scan\n\n## Page 1\n\nDuplicate word word appears twice.\n";
        KnowledgeChunk::create([
            'tenant_id' => 'default',
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $markdown),
            'chunk_text' => $markdown,
            'metadata' => [],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->proposeCorrection($doc, 1, 'word', 'term', null, 'user:1');
    }

    /**
     * A correction must never bleed across a page boundary: the same
     * text on PAGE 2 (unrelated boilerplate) is not the one on page 1 —
     * proposing against page 1 with text that ONLY exists on page 2 is
     * refused, proving the page split (not a whole-document search) gates
     * the match.
     */
    public function test_propose_correction_does_not_match_text_from_a_different_page(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithContent();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->proposeCorrection($doc, 1, 'unrelated boilerplate', 'x', null, 'user:1');
    }

    public function test_propose_correction_refuses_oversize_new_text(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithContent();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->proposeCorrection($doc, 1, 'Bod', str_repeat('x', 4001), null, 'user:1');
    }

    public function test_propose_correction_refuses_oversize_rationale(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithContent();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', str_repeat('x', 501), 'user:1');
    }

    public function test_propose_correction_replay_returns_the_same_candidate_not_a_duplicate(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithContent();

        $first = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', 'r', 'user:1');
        $second = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', 'r', 'user:1');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('kb_text_correction_candidates', 1);
    }

    public function test_propose_correction_enforces_the_hourly_rate_limit_per_actor(): void
    {
        config(['kb.review.enabled' => true, 'kb.review.candidates_per_hour' => 1]);
        $doc = $this->docWithContent();
        $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        $this->expectException(KbReviewRateLimitedException::class);
        // A genuinely NEW proposal (different old/new pair) after the
        // budget is spent must be refused.
        $this->svc->proposeCorrection($doc, 2, 'unrelated', 'other', null, 'user:1');
    }

    /**
     * A replayed (idempotent) call must NEVER be refused by the rate
     * limiter — KbReviewService resolves the existing candidate BEFORE
     * touching RateLimiter.
     */
    public function test_propose_correction_replay_does_not_spend_the_rate_limit_budget(): void
    {
        config(['kb.review.enabled' => true, 'kb.review.candidates_per_hour' => 1]);
        $doc = $this->docWithContent();
        $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        // Same call again — a replay, not a new proposal.
        $replay = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        $this->assertSame(KbTextCorrectionCandidate::STATUS_PENDING, $replay->status);
        $this->assertDatabaseCount('kb_text_correction_candidates', 1);
    }

    public function test_propose_correction_different_actors_have_independent_budgets(): void
    {
        config(['kb.review.enabled' => true, 'kb.review.candidates_per_hour' => 1]);
        $doc = $this->docWithContent();
        $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        // user:2's budget is untouched by user:1's proposal.
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bobby', null, 'user:2');

        $this->assertSame('user:2', $candidate->proposed_by);
    }

    // --- approveCorrection ----------------------------------------------

    public function test_approve_correction_throws_when_disabled(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->doc();
        $candidate = KbTextCorrectionCandidate::create([
            'tenant_id' => 'default',
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'version_hash' => $doc->version_hash,
            'old_text' => 'x',
            'new_text' => 'y',
            'idempotency_key' => hash('sha256', 'k'),
            'status' => KbTextCorrectionCandidate::STATUS_PENDING,
            'proposed_by' => 'user:1',
        ]);

        $this->expectException(KbReviewDisabledException::class);
        $this->svc->approveCorrection($candidate, 'user:2', null);
    }

    public function test_approve_correction_writes_the_corrected_markdown_dispatches_ingest_and_audits(): void
    {
        Queue::fake();
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $reviewer = $this->user();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', 'ocr misread', 'user:1');

        $result = $this->svc->approveCorrection($candidate, "user:{$reviewer->id}", (int) $reviewer->id);

        $this->assertTrue($result['applied']);
        $candidate->refresh();
        $this->assertSame(KbTextCorrectionCandidate::STATUS_APPLIED, $candidate->status);
        $this->assertNotNull($candidate->consumed_at);
        $this->assertSame((int) $reviewer->id, $candidate->consumed_by);

        // The corrected byte replaced "Bod" -> "Bob" ONLY within page 1's
        // section, page 2's unrelated text is untouched, and page 2's own
        // heading survives (proving the replacement did not shift/clobber
        // the rest of the document).
        $onDisk = (string) Storage::disk('kb')->get($doc->source_path);
        $this->assertStringContainsString('Bob', $onDisk);
        $this->assertStringNotContainsString('Bod ', $onDisk);
        $this->assertStringContainsString(self::PAGE_2, $onDisk);
        $this->assertStringContainsString('## Page 2', $onDisk);

        Queue::assertPushed(IngestDocumentJob::class, 1);

        $this->assertDatabaseHas('kb_canonical_audit', [
            'event_type' => 'updated',
            'actor' => "user:{$reviewer->id}",
        ]);
        $audit = KbCanonicalAudit::query()->latest('id')->first();
        $this->assertSame('kb_review_correction_candidate', $audit->metadata_json['source']);
        $this->assertSame($candidate->id, $audit->metadata_json['candidate_id']);
    }

    /**
     * R21 — single-use. A second approval attempt on an already-applied
     * candidate must be a safe no-op: no second disk write, no second
     * job, no second audit row.
     */
    public function test_approve_correction_on_an_already_applied_candidate_is_a_safe_no_op(): void
    {
        Queue::fake();
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
        $this->svc->approveCorrection($candidate, 'user:1', null);
        Queue::assertPushed(IngestDocumentJob::class, 1);
        $auditsAfterFirst = KbCanonicalAudit::count();

        $result = $this->svc->approveCorrection($candidate->fresh(), 'user:2', null);

        $this->assertFalse($result['applied']);
        $this->assertSame('already_consumed', $result['reason']);
        Queue::assertPushed(IngestDocumentJob::class, 1); // still exactly one
        $this->assertSame($auditsAfterFirst, KbCanonicalAudit::count());
    }

    /**
     * R21 (mirrors KbReviewServiceTest::test_approve_decides_from_a_fresh_read_...
     * — SQLite cannot enforce real cross-connection blocking on
     * lockForUpdate(), so a true two-connection race isn't stageable here;
     * what IS directly testable, and is exactly the bug shape R21 closes,
     * is that the decision comes from a FRESH read of the row, not from
     * the caller's stale in-memory instance). Simulate "a concurrent
     * reviewer already approved this candidate" by flipping the DB row
     * directly (bypassing the in-memory $candidate, which still reports
     * pending), then call approveCorrection() with that stale instance.
     */
    public function test_approve_correction_decides_from_a_fresh_read_not_the_callers_stale_instance(): void
    {
        Queue::fake();
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        DB::table('kb_text_correction_candidates')->where('id', $candidate->id)->update([
            'status' => KbTextCorrectionCandidate::STATUS_APPLIED,
            'consumed_at' => now(),
            'consumed_by' => null,
        ]);

        $this->assertSame(
            KbTextCorrectionCandidate::STATUS_PENDING,
            $candidate->status,
            'the in-memory $candidate must still report pending — the concurrent write bypassed it',
        );

        $result = $this->svc->approveCorrection($candidate, 'user:2', null);

        $this->assertFalse($result['applied']);
        $this->assertSame('already_consumed', $result['reason']);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('kb_canonical_audit', 0);
    }

    /**
     * ADR 0031 §6 — "a correction proposed against version N must not
     * silently apply to version N+1's different text". Simulate a later
     * edit that removed the proposed-against text from page 1 (a new
     * chunk replaces the old one) — approval must REJECT the now-stale
     * candidate rather than write a correction against text that is no
     * longer there.
     */
    public function test_approve_correction_rejects_a_candidate_whose_old_text_no_longer_matches(): void
    {
        Queue::fake();
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        // Page 1 was edited since the proposal: "Bod" no longer appears.
        KnowledgeChunk::where('knowledge_document_id', $doc->id)->delete();
        $revised = "# scan\n\n## Page 1\n\nCompletely rewritten page text.\n\n## Page 2\n\n".self::PAGE_2."\n";
        KnowledgeChunk::create([
            'tenant_id' => 'default',
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $revised),
            'chunk_text' => $revised,
            'metadata' => [],
        ]);

        $result = $this->svc->approveCorrection($candidate, 'user:1', null);

        $this->assertFalse($result['applied']);
        $this->assertSame('stale_old_text_not_found_or_ambiguous', $result['reason']);
        $candidate->refresh();
        $this->assertSame(KbTextCorrectionCandidate::STATUS_REJECTED, $candidate->status);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('kb_canonical_audit', 0);
    }

    // --- rejectCorrection -------------------------------------------------

    public function test_reject_correction_throws_when_disabled(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->doc();
        $candidate = KbTextCorrectionCandidate::create([
            'tenant_id' => 'default',
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'version_hash' => $doc->version_hash,
            'old_text' => 'x',
            'new_text' => 'y',
            'idempotency_key' => hash('sha256', 'k2'),
            'status' => KbTextCorrectionCandidate::STATUS_PENDING,
            'proposed_by' => 'user:1',
        ]);

        $this->expectException(KbReviewDisabledException::class);
        $this->svc->rejectCorrection($candidate, null);
    }

    public function test_reject_correction_marks_the_candidate_rejected(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithContent();
        $reviewer = $this->user();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        $result = $this->svc->rejectCorrection($candidate, (int) $reviewer->id);

        $this->assertTrue($result['rejected']);
        $candidate->refresh();
        $this->assertSame(KbTextCorrectionCandidate::STATUS_REJECTED, $candidate->status);
        $this->assertNotNull($candidate->consumed_at);
        $this->assertSame((int) $reviewer->id, $candidate->consumed_by);
    }

    public function test_reject_correction_on_an_already_consumed_candidate_is_a_safe_no_op(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithContent();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
        $this->svc->rejectCorrection($candidate, null);

        $result = $this->svc->rejectCorrection($candidate->fresh(), null);

        $this->assertFalse($result['rejected']);
        $this->assertSame('already_consumed', $result['reason']);
    }
}
