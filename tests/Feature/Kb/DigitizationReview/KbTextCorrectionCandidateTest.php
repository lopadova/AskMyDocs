<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\DigitizationReview;

use App\Models\KbTextCorrectionCandidate;
use App\Models\KnowledgeDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.37/W3 (ADR 0031 §6-7) — schema-level regression coverage for the
 * text-correction-candidate table, ahead of KbProposeTextCorrectionTool
 * landing in a later W3 sub-branch. Proves: (a) `idempotency_key` really
 * has a DB-level UNIQUE constraint (the mechanism R21 relies on to make a
 * replayed MCP tool call a no-op rather than a duplicate row), (b) the
 * static helper produces a deterministic, input-sensitive hash, (c) the
 * FK cascade-deletes candidates with their document, and (d) the pending
 * scope filters by status.
 */
final class KbTextCorrectionCandidateTest extends TestCase
{
    use RefreshDatabase;

    private function makeDoc(): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'Scanned page',
            'source_path' => 'docs/scan.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => null,
        ]);
    }

    /** @return array<string,mixed> */
    private function candidateAttributes(KnowledgeDocument $doc, string $idempotencyKey): array
    {
        return [
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'version_hash' => $doc->version_hash,
            'old_text' => 'teh cache',
            'new_text' => 'the cache',
            'rationale' => 'typo',
            'idempotency_key' => $idempotencyKey,
            'status' => KbTextCorrectionCandidate::STATUS_PENDING,
            'proposed_by' => 'agent:claude',
        ];
    }

    public function test_idempotency_key_has_a_database_level_unique_constraint(): void
    {
        $doc = $this->makeDoc();

        KbTextCorrectionCandidate::create($this->candidateAttributes($doc, str_repeat('c', 64)));

        $this->expectException(QueryException::class);

        // Same key, different page/text — the constraint keys ONLY on
        // idempotency_key, so a replayed call with the exact same
        // fingerprint must collide even if some other field diverges.
        KbTextCorrectionCandidate::create([
            ...$this->candidateAttributes($doc, str_repeat('c', 64)),
            'page_number' => 2,
        ]);
    }

    public function test_idempotency_key_for_is_deterministic_and_input_sensitive(): void
    {
        $key = fn (string $oldText) => KbTextCorrectionCandidate::idempotencyKeyFor(
            tenantId: 'default',
            userIdentity: 'agent:claude',
            documentId: 42,
            versionHash: str_repeat('b', 64),
            pageNumber: 1,
            oldText: $oldText,
            newText: 'the cache',
        );

        $this->assertSame($key('teh cache'), $key('teh cache'), 'same inputs must hash identically');
        $this->assertNotSame($key('teh cache'), $key('the cach'), 'a different old_text must change the hash');
        $this->assertSame(64, strlen($key('teh cache')), 'sha256 hex digest is 64 chars');
    }

    /**
     * Copilot PR #494 (critical) — a naive delimiter-joined preimage is NOT
     * injective when old_text/new_text are arbitrary OCR text: shifting a
     * delimiter character across the old_text/new_text boundary can
     * reproduce the exact same joined string for two GENUINELY DIFFERENT
     * proposals. Here "teh cache." + " the cach" and "teh cache" + ". the
     * cach" both join (on '.') to "teh cache. the cach" — a real collision
     * under the old implementation. The fixed implementation hashes each
     * field to a fixed-length digest BEFORE concatenating, so the two
     * distinct 7-tuples must produce distinct keys.
     */
    public function test_idempotency_key_for_does_not_collide_across_a_shifted_field_boundary(): void
    {
        $key = fn (string $oldText, string $newText) => KbTextCorrectionCandidate::idempotencyKeyFor(
            tenantId: 'default',
            userIdentity: 'agent:claude',
            documentId: 42,
            versionHash: str_repeat('b', 64),
            pageNumber: 1,
            oldText: $oldText,
            newText: $newText,
        );

        $a = $key('teh cache.', ' the cach');
        $b = $key('teh cache', '. the cach');

        $this->assertNotSame(
            $a,
            $b,
            'shifting a delimiter across the old_text/new_text boundary must NOT collide',
        );
    }

    public function test_deleting_the_document_cascades_its_correction_candidates(): void
    {
        $doc = $this->makeDoc();
        $candidate = KbTextCorrectionCandidate::create($this->candidateAttributes($doc, str_repeat('d', 64)));

        $doc->forceDelete();

        $this->assertDatabaseMissing('kb_text_correction_candidates', ['id' => $candidate->id]);
    }

    public function test_pending_scope_filters_out_applied_and_rejected_candidates(): void
    {
        $doc = $this->makeDoc();
        KbTextCorrectionCandidate::create([
            ...$this->candidateAttributes($doc, str_repeat('e', 64)),
            'status' => KbTextCorrectionCandidate::STATUS_PENDING,
        ]);
        KbTextCorrectionCandidate::create([
            ...$this->candidateAttributes($doc, str_repeat('f', 64)),
            'status' => KbTextCorrectionCandidate::STATUS_APPLIED,
        ]);
        KbTextCorrectionCandidate::create([
            ...$this->candidateAttributes($doc, str_repeat('0', 64)),
            'status' => KbTextCorrectionCandidate::STATUS_REJECTED,
        ]);

        $this->assertSame(1, KbTextCorrectionCandidate::pending()->count());
        $this->assertSame(
            str_repeat('e', 64),
            KbTextCorrectionCandidate::pending()->first()->idempotency_key,
        );
    }
}
