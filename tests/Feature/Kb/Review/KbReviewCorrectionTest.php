<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Review;

use App\Ai\EmbeddingsResponse;
use App\Exceptions\KbReviewDisabledException;
use App\Exceptions\KbReviewRateLimitedException;
use App\Models\KbCanonicalAudit;
use App\Models\KbTextCorrectionCandidate;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Review\KbReviewService;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
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
 * document) — no artifact/Storage setup needed for the READ path.
 * approveCorrection()'s WRITE path runs the REAL DocumentIngestor::
 * reembedFromMarkdown() (v8.37/W3b round 1 findings #2/#3) — needs
 * Storage::fake('kb') for the artifact write AND a faked
 * EmbeddingCacheService so the chunk/embed step never reaches a real
 * provider (mirrors ConversionArtifactsIngestTest's setUp()).
 */
final class KbReviewCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private KbReviewService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '']);
        Storage::fake('kb');

        // Bound BEFORE KbReviewService is resolved below: the container
        // builds DocumentIngestor's EmbeddingCacheService dependency
        // eagerly at construction time, so binding the mock any later
        // leaves $this->svc holding a DocumentIngestor wired to the REAL
        // service (and a real outbound HTTP call on the first approval
        // that actually reaches reembedFromMarkdown()).
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(fn () => array_fill(0, 8, 0.0), $texts),
                provider: 'fake',
                model: 'fake-8',
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);

        $this->svc = app(KbReviewService::class);
    }

    protected function tearDown(): void
    {
        // R41 — rollback first, Mockery::close() after: a throw here must
        // never skip the RefreshDatabase rollback.
        parent::tearDown();
        Mockery::close();
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

    /**
     * A document whose content is readable via chunk-reconstruction: one
     * chunk whose text is the whole `## Page N`-delimited document.
     *
     * @param  array<string,mixed>  $over
     */
    private function docWithContent(array $over = []): KnowledgeDocument
    {
        $doc = $this->doc($over);
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
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();

        try {
            $this->svc->proposeCorrection($doc, 1, 'never appears anywhere', 'x', null, 'user:1');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // expected
        }

        // ADR 0031 §6 — every DENIED outcome is audited (Copilot PR #496
        // round 2 finding), including old_text-not-found/ambiguous — not
        // only rate-limit denials.
        $audit = KbCanonicalAudit::query()->where('event_type', 'correction_proposed')->sole();
        $this->assertSame('denied', $audit->metadata_json['outcome']);
        $this->assertSame('old_text_not_found_or_ambiguous', $audit->metadata_json['reason']);
    }

    /** Every basic-input-shape refusal is audited too — not only content-validation denials. */
    public function test_propose_correction_invalid_page_number_is_audited(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();

        try {
            $this->svc->proposeCorrection($doc, 0, 'Bod', 'Bob', null, 'user:1');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $audit = KbCanonicalAudit::query()->where('event_type', 'correction_proposed')->sole();
        $this->assertSame('denied', $audit->metadata_json['outcome']);
        $this->assertSame('invalid_page_number', $audit->metadata_json['reason']);
    }

    public function test_propose_correction_empty_old_text_is_audited(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();

        try {
            $this->svc->proposeCorrection($doc, 1, '   ', 'Bob', null, 'user:1');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $audit = KbCanonicalAudit::query()->where('event_type', 'correction_proposed')->sole();
        $this->assertSame('denied', $audit->metadata_json['outcome']);
        $this->assertSame('empty_old_text', $audit->metadata_json['reason']);
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

    /**
     * ADR 0031 §6 — "every accepted, denied, and replayed call writes an
     * audit row" (Copilot PR #496 round 1 finding #8). Three separate
     * proposeCorrection() outcomes, one audit row per outcome.
     */
    public function test_propose_correction_accepted_call_writes_an_audit_row(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();

        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        $audit = KbCanonicalAudit::query()->where('event_type', 'correction_proposed')->sole();
        $this->assertSame('accepted', $audit->metadata_json['outcome']);
        $this->assertSame('user:1', $audit->actor);
        $this->assertSame($candidate->id, $audit->after_json['candidate_id']);
    }

    public function test_propose_correction_replayed_call_writes_an_audit_row(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        $this->assertSame(1, KbCanonicalAudit::query()->where('event_type', 'correction_proposed')->where('metadata_json->outcome', 'accepted')->count());
        $this->assertSame(1, KbCanonicalAudit::query()->where('event_type', 'correction_proposed')->where('metadata_json->outcome', 'replayed')->count());
    }

    public function test_propose_correction_rate_limited_denial_writes_an_audit_row(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true, 'kb.review.candidates_per_hour' => 1]);
        $doc = $this->docWithContent();
        $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');

        try {
            $this->svc->proposeCorrection($doc, 2, 'unrelated', 'other', null, 'user:1');
            $this->fail('expected KbReviewRateLimitedException');
        } catch (KbReviewRateLimitedException) {
            // expected
        }

        $denied = KbCanonicalAudit::query()->where('event_type', 'correction_proposed')->where('metadata_json->outcome', 'denied')->sole();
        $this->assertSame('rate_limited', $denied->metadata_json['reason']);
        $this->assertNull($denied->after_json);
    }

    /**
     * R21 — the check-then-act sequence (existing-check, rate-limit
     * check-and-hit, insert) is race-protected by a per-TENANT+ACTOR
     * `Cache::lock()` (Copilot PR #496 round 2 finding — round 1's lock,
     * keyed on the idempotency key, only serialized IDENTICAL proposals; a
     * per-actor lock covers DIFFERENT concurrent proposals from the same
     * actor too). Not stageable as a true concurrent/forked race in
     * PHPUnit (Copilot PR #496 round 3 — mirrors KbReviewCorrectionTest's
     * own R21 precedent for approveCorrection's lockForUpdate() below, and
     * KbReviewServiceTest's for setPageReviewStatus()): a genuinely
     * concurrent test would need real OS-level parallelism (threads/forked
     * processes racing the SAME lock store and rate limiter), which this
     * suite's single-process, single-connection SQLite run cannot provide.
     * What IS directly testable — sequentially, but exercising the SAME
     * shared-budget invariant the lock protects — is that N calls with the
     * IDENTICAL 7-tuple (a replay, this test) spend the actor's budget
     * exactly ONCE, and that a genuinely DIFFERENT proposal from the SAME
     * actor draws from the SAME remaining budget rather than a separate
     * one (asserted below, and in
     * `test_propose_correction_enforces_the_hourly_rate_limit_per_actor`).
     */
    public function test_propose_correction_n_identical_calls_spend_the_rate_limit_budget_exactly_once(): void
    {
        // Budget of 2: the identical batch below must spend exactly 1 unit
        // (the first, genuinely-new proposal) — leaving exactly 1 unit for
        // the later genuinely-different proposal. If any of the 4 replays
        // in the batch had ALSO spent a unit, the budget would already be
        // exhausted and the fresh proposal below would throw.
        config(['kb.review.enabled' => true, 'kb.review.candidates_per_hour' => 2]);
        $doc = $this->docWithContent();

        for ($i = 0; $i < 5; $i++) {
            $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
        }

        $this->assertDatabaseCount('kb_text_correction_candidates', 1);
        $fresh = $this->svc->proposeCorrection($doc, 2, 'unrelated', 'other', null, 'user:1');
        $this->assertSame('user:1', $fresh->proposed_by);

        // The budget is now exhausted (2/2 spent: 1 for the batch's genuine
        // insert, 1 for the fresh proposal) — a THIRD genuinely different
        // proposal must be refused.
        $this->expectException(KbReviewRateLimitedException::class);
        $this->svc->proposeCorrection($doc, 2, 'unrelated', 'yet another', null, 'user:1');
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

    /**
     * v8.37/W3b round 1 (Copilot findings #2/#3) — approval no longer
     * overwrites `source_path` and queues a job; it creates a genuinely
     * NEW version through `DocumentIngestor::reembedFromMarkdown()`. The
     * ORIGINAL row is archived (never mutated), `source_path` never
     * touched on disk, and the corrected text lives in the new version's
     * own chunks.
     */
    public function test_approve_correction_creates_a_new_version_and_audits(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $reviewer = $this->user();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', 'ocr misread', 'user:1');

        $result = $this->svc->approveCorrection($candidate, "user:{$reviewer->id}", (int) $reviewer->id);

        $this->assertTrue($result['applied']);
        $this->assertArrayHasKey('document_id', $result);
        $this->assertNotSame($doc->id, $result['document_id'], 'approval must create a NEW version, never mutate the proposed-against row');

        $candidate->refresh();
        $this->assertSame(KbTextCorrectionCandidate::STATUS_APPLIED, $candidate->status);
        $this->assertNotNull($candidate->consumed_at);
        $this->assertSame((int) $reviewer->id, $candidate->consumed_by);

        // The original row is archived, never mutated in place; its
        // source_path binary on disk is untouched (finding #2 — approval
        // must never overwrite the original source with corrected text).
        $doc->refresh();
        $this->assertSame('archived', $doc->status);
        $this->assertFalse(Storage::disk('kb')->exists($doc->source_path), 'approval must never write to the original source_path on disk');

        /** @var KnowledgeDocument $newVersion */
        $newVersion = KnowledgeDocument::query()->findOrFail($result['document_id']);
        $this->assertSame('active', $newVersion->status);
        $this->assertSame($doc->source_path, $newVersion->source_path);
        $this->assertNotSame($doc->version_hash, $newVersion->version_hash);

        // The corrected byte replaced "Bod" -> "Bob" ONLY within page 1's
        // section; page 2's unrelated text is untouched (proving the
        // replacement did not shift/clobber the rest of the document).
        // PdfPageChunker slices per `## Page N` boundary into one chunk per
        // page (heading tracked in `heading_path`/`metadata.page`, not
        // inlined into `chunk_text`), so the page split is asserted
        // directly on the new version's own chunk rows.
        $newChunks = KnowledgeChunk::query()->where('knowledge_document_id', $newVersion->id)->orderBy('chunk_order')->get();
        $this->assertCount(2, $newChunks, 'one chunk per page');
        $this->assertSame(1, $newChunks[0]->metadata['page'] ?? null);
        $this->assertStringContainsString('Bob', $newChunks[0]->chunk_text);
        $this->assertStringNotContainsString('Bod ', $newChunks[0]->chunk_text);
        $this->assertSame(2, $newChunks[1]->metadata['page'] ?? null);
        $this->assertSame(self::PAGE_2, trim($newChunks[1]->chunk_text));

        $this->assertDatabaseHas('kb_canonical_audit', [
            'event_type' => 'updated',
            'actor' => "user:{$reviewer->id}",
        ]);
        $audit = KbCanonicalAudit::query()->where('event_type', 'updated')->latest('id')->first();
        $this->assertSame('kb_review_correction_candidate', $audit->metadata_json['source']);
        $this->assertSame($candidate->id, $audit->metadata_json['candidate_id']);
    }

    /**
     * R21 — single-use. A second approval attempt on an already-applied
     * candidate must be a safe no-op: no second version, no second audit
     * row.
     */
    public function test_approve_correction_on_an_already_applied_candidate_is_a_safe_no_op(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
        $this->svc->approveCorrection($candidate, 'user:1', null);
        $auditsAfterFirst = KbCanonicalAudit::count();
        $docsAfterFirst = KnowledgeDocument::count();

        $result = $this->svc->approveCorrection($candidate->fresh(), 'user:2', null);

        $this->assertFalse($result['applied']);
        $this->assertSame('already_consumed', $result['reason']);
        $this->assertSame($auditsAfterFirst, KbCanonicalAudit::count());
        $this->assertSame($docsAfterFirst, KnowledgeDocument::count(), 'a no-op approval must never mint a second version');
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
     *
     * Copilot PR #496 round 1 finding #9 — this test alone proves the
     * FRESH-READ decision but not "exactly one disk/version/audit effect"
     * under a genuine two-caller race (which SQLite cannot stage). That
     * second half of the invariant is proven — with REAL sequential
     * transactions, no DB bypass — by
     * {@see test_approve_correction_on_an_already_applied_candidate_is_a_safe_no_op()}
     * just above: it calls approveCorrection() twice for real and asserts
     * the document count and audit count are IDENTICAL before and after
     * the second call, i.e. exactly one version and one audit row survive
     * two callers racing the same candidate, regardless of which one
     * "wins" the lock first.
     */
    public function test_approve_correction_decides_from_a_fresh_read_not_the_callers_stale_instance(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
        $auditsBeforeApprove = KbCanonicalAudit::count();
        $docsBeforeApprove = KnowledgeDocument::count();

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
        $this->assertSame($auditsBeforeApprove, KbCanonicalAudit::count(), 'a rejected already_consumed attempt must never write a new audit row');
        $this->assertSame($docsBeforeApprove, KnowledgeDocument::count(), 'a rejected already_consumed attempt must never mint a new version');
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
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
        $auditsBeforeApprove = KbCanonicalAudit::count();
        $docsBeforeApprove = KnowledgeDocument::count();

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
        $this->assertSame($auditsBeforeApprove, KbCanonicalAudit::count(), 'a stale-text rejection must never write a new audit row');
        $this->assertSame($docsBeforeApprove, KnowledgeDocument::count(), 'a stale-text rejection must never mint a new version');
    }

    /**
     * v8.37/W3b round 1 (Copilot PR #496 finding #1) — the row locked and
     * written to must be the family's ACTUAL live row, not a bare lock on
     * whatever row the candidate happens to name. Simulate the exact gap
     * the fix closes: the document is archived (e.g. by a concurrent
     * re-ingest, or here directly) between the proposal and the approval,
     * with the family left holding NO active row —
     * `currentVersionFor()`'s own documented fallback returns the (now
     * stale) `$document` unlocked. Phase 1 (round 2's redesign) still
     * validates and CLAIMS the candidate on that stale read (nothing there
     * changed), but phase 2's `reembedFromMarkdown()` re-checks under its
     * OWN `assertDocumentStillActive()`/`lockForUpdate()` that the row is
     * STILL active at write time, throws
     * `ReembedTargetNoLongerActiveException`, and the phase-1 claim is
     * reverted to REJECTED rather than left stranded `applied` with no
     * version.
     */
    public function test_approve_correction_rejects_a_candidate_whose_document_is_no_longer_active(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
        $auditsBeforeApprove = KbCanonicalAudit::count();
        $docsBeforeApprove = KnowledgeDocument::count();

        DB::table('knowledge_documents')->where('id', $doc->id)->update(['status' => 'archived']);

        $result = $this->svc->approveCorrection($candidate, 'user:1', null);

        $this->assertFalse($result['applied']);
        $this->assertSame('stale_version_no_longer_active', $result['reason']);
        $candidate->refresh();
        $this->assertSame(KbTextCorrectionCandidate::STATUS_REJECTED, $candidate->status);
        $this->assertSame($auditsBeforeApprove, KbCanonicalAudit::count(), 'a no-longer-active rejection must never write a new audit row');
        $this->assertSame($docsBeforeApprove, KnowledgeDocument::count(), 'a no-longer-active rejection must never mint a new version');
    }

    /**
     * v8.37/W3b round 2 (Copilot PR #496 finding) — a CANONICAL document's
     * Markdown carries YAML frontmatter that chunk-reconstruction
     * (`contentFor()`'s fallback when no artifact is retained) explicitly
     * drops; applying a correction through `reembedFromMarkdown()` on such
     * a document would silently demote it out of canonical status. This
     * feature targets ordinary OCR/PDF scans — a canonical document's own
     * approval path is `approve()`'s `WikiExplorerService::promote()`
     * branch — so approveCorrection refuses outright rather than risk it.
     */
    public function test_approve_correction_refuses_a_canonical_document(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent(['is_canonical' => true]);
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
        $auditsBeforeApprove = KbCanonicalAudit::count();
        $docsBeforeApprove = KnowledgeDocument::count();

        $result = $this->svc->approveCorrection($candidate, 'user:1', null);

        $this->assertFalse($result['applied']);
        $this->assertSame('canonical_document_not_supported', $result['reason']);
        $candidate->refresh();
        $this->assertSame(KbTextCorrectionCandidate::STATUS_REJECTED, $candidate->status);
        $this->assertSame($auditsBeforeApprove, KbCanonicalAudit::count());
        $this->assertSame($docsBeforeApprove, KnowledgeDocument::count());
    }

    /**
     * v8.37/W3b round 2 (Copilot PR #496 finding — "defer reembedding side
     * effects until the outer transaction commits") — approveCorrection now
     * calls `reembedFromMarkdown()` OUTSIDE any ambient transaction (phase
     * 2). If that call throws for a reason OTHER than staleness (an infra
     * failure — embedding provider down, artifact publish failure, ...),
     * the phase-1 claim (already committed `applied`) must be REVERTED to
     * `pending` — never left stranded `applied` with no corresponding
     * version — and the failure must propagate loudly (R14), not be
     * swallowed.
     */
    public function test_approve_correction_reverts_the_claim_to_pending_on_a_generic_reembed_failure(): void
    {
        config(['kb.review.enabled' => true, 'kb.canonical.audit_enabled' => true]);
        $doc = $this->docWithContent();
        $candidate = $this->svc->proposeCorrection($doc, 1, 'Bod', 'Bob', null, 'user:1');
        $auditsBeforeApprove = KbCanonicalAudit::count();
        $docsBeforeApprove = KnowledgeDocument::count();

        $ingestor = Mockery::mock(\App\Services\Kb\DocumentIngestor::class);
        $ingestor->shouldReceive('reembedFromMarkdown')->once()->andThrow(new \RuntimeException('embedding provider unreachable'));
        $this->app->instance(\App\Services\Kb\DocumentIngestor::class, $ingestor);
        $svc = app(KbReviewService::class);

        try {
            $svc->approveCorrection($candidate, 'user:1', null);
            $this->fail('expected the generic reembed failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('embedding provider unreachable', $e->getMessage());
        }

        $candidate->refresh();
        $this->assertSame(KbTextCorrectionCandidate::STATUS_PENDING, $candidate->status, 'the phase-1 claim must be reverted to pending, not left stranded applied');
        $this->assertNull($candidate->consumed_at);
        $this->assertNull($candidate->consumed_by);
        $this->assertSame($auditsBeforeApprove, KbCanonicalAudit::count());
        $this->assertSame($docsBeforeApprove, KnowledgeDocument::count());

        // The reverted candidate is retryable: a fresh attempt with the
        // REAL DocumentIngestor (this test's own $this->svc, wired to the
        // faked EmbeddingCacheService from setUp()) succeeds.
        $result = $this->svc->approveCorrection($candidate->fresh(), 'user:1', null);
        $this->assertTrue($result['applied']);
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
