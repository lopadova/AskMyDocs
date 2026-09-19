<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Services\Kb\Reranker;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * v8.11 — the anti-hallucination firewall: with everything else equal, a
 * human-curated `accepted` doc outranks an AUTO-tier doc, which still outranks
 * a raw (non-canonical) doc. Setting the penalty to 0 disables the firewall.
 */
final class RerankerAutoTierFirewallTest extends TestCase
{
    /** @return array<string,mixed> */
    private function chunk(int $id, bool $canonical, string $generationSource, bool $ocrOrigin = false): array
    {
        return [
            'chunk_id' => $id,
            'chunk_text' => 'cache strategy details and configuration',
            'heading_path' => 'Cache',
            'vector_score' => 0.80,
            'document' => [
                'id' => $id,
                'title' => "doc {$id}",
                'is_canonical' => $canonical,
                'canonical_status' => $canonical ? 'accepted' : null,
                'retrieval_priority' => 50,
                'generation_source' => $generationSource,
                'ocr_origin' => $ocrOrigin,
            ],
        ];
    }

    private function scoreById(Collection $ranked): array
    {
        return $ranked->mapWithKeys(fn (array $c) => [(int) $c['chunk_id'] => (float) $c['rerank_score']])->all();
    }

    /**
     * @return array<int, float> chunk_id => rerank_detail.canonical_penalty
     */
    private function canonicalPenaltyById(Collection $ranked): array
    {
        return $ranked->mapWithKeys(fn (array $c) => [(int) $c['chunk_id'] => (float) $c['rerank_detail']['canonical_penalty']])->all();
    }

    public function test_human_outranks_auto_outranks_raw(): void
    {
        config([
            'kb.reranking.enabled' => true,
            'kb.canonical.priority_weight' => 0.001,
            'kb.canonical.auto_tier_penalty' => 0.02,
        ]);

        $chunks = collect([
            $this->chunk(1, canonical: true, generationSource: 'human'),
            $this->chunk(2, canonical: true, generationSource: 'auto'),
            $this->chunk(3, canonical: false, generationSource: 'human'),
        ]);

        $ranked = (new Reranker)->rerank('cache strategy', $chunks, limit: 10);
        $scores = $this->scoreById($ranked);

        // Same base (identical text/vector); only the canonical tier differs.
        $this->assertGreaterThan($scores[2], $scores[1], 'human accepted must outrank auto');
        $this->assertGreaterThan($scores[3], $scores[2], 'auto must outrank raw');
        // Order in the returned collection follows the scores.
        $this->assertSame([1, 2, 3], $ranked->pluck('chunk_id')->map('intval')->all());
    }

    /**
     * v8.37/W3 (ADR 0031 §5) — before this fix, canonicalAdjustment()
     * returned a zero delta before ever reading generation_source for any
     * non-canonical row, so an unreviewed OCR'd scan (`auto`) and a
     * reviewed one (`human`) ranked identically. Two non-canonical chunks,
     * otherwise identical, differing ONLY in generation_source: the
     * reviewed one must now strictly outrank the unreviewed one.
     *
     * Copilot PR #494 round 6 — the unreviewed chunk is now explicitly
     * `ocr_origin: true`, since round 6 scoped the non-canonical penalty to
     * OCR-originated rows specifically (an `auto` row that ISN'T
     * OCR-originated — e.g. AutoWiki-enriched raw content — no longer pays
     * it; see the new test below).
     */
    public function test_reviewed_scan_outranks_unreviewed_scan_when_both_are_non_canonical(): void
    {
        config([
            'kb.reranking.enabled' => true,
            'kb.canonical.priority_weight' => 0.001,
            'kb.canonical.auto_tier_penalty' => 0.02,
        ]);

        $chunks = collect([
            $this->chunk(1, canonical: false, generationSource: 'auto', ocrOrigin: true),
            $this->chunk(2, canonical: false, generationSource: 'human'),
        ]);

        $ranked = (new Reranker)->rerank('cache strategy', $chunks, limit: 10);
        $scores = $this->scoreById($ranked);

        $this->assertGreaterThan($scores[1], $scores[2], 'a reviewed (human) non-canonical scan must outrank an unreviewed (auto) OCR one');
        $this->assertSame([2, 1], $ranked->pluck('chunk_id')->map('intval')->all());
    }

    /**
     * Copilot PR #494 round 6 (must-fix) — `generation_source='auto'` on a
     * non-canonical row is NOT exclusive to OCR: `AutoWikiCompiler` marks
     * enriched RAW documents `'auto'` too ("enriches raw / already-auto
     * documents", per its own docblock — not only OCR'd scans), and those
     * rows were NEVER penalized before this PR. Applying the OCR-review
     * penalty by `generation_source` alone (with no origin check) silently
     * demoted every existing AutoWiki-enriched raw document below an
     * unenriched sibling — reversing established ranking for content that
     * was never touched by Digitization Review. A non-canonical `auto` row
     * WITHOUT `ocr_origin` must pay ZERO penalty and tie with a
     * non-canonical `human` row.
     */
    public function test_non_canonical_auto_wiki_rows_without_ocr_origin_are_not_penalized(): void
    {
        config([
            'kb.reranking.enabled' => true,
            'kb.canonical.priority_weight' => 0.001,
            'kb.canonical.auto_tier_penalty' => 0.02,
        ]);

        $chunks = collect([
            $this->chunk(1, canonical: false, generationSource: 'auto', ocrOrigin: false),
            $this->chunk(2, canonical: false, generationSource: 'human'),
        ]);

        $ranked = (new Reranker)->rerank('cache strategy', $chunks, limit: 10);
        $scores = $this->scoreById($ranked);
        $penalties = $this->canonicalPenaltyById($ranked);

        $this->assertSame(0.0, $penalties[1], 'a non-canonical AutoWiki-enriched (non-OCR) row must pay zero auto-tier penalty');
        $this->assertEqualsWithDelta($scores[1], $scores[2], 1e-9, 'a non-canonical AutoWiki row must tie with a non-canonical human row when neither is OCR-origin');
    }

    /**
     * Copilot PR #494 round 6 — the CANONICAL branch is unchanged: it has
     * always paid the auto-tier penalty regardless of origin (the
     * pre-v8.37 v8.11 firewall). A canonical `auto` row with NO
     * `ocr_origin` (an AutoWiki-generated canonical wiki page, the original
     * v8.11 case this firewall was built for) must still be penalized
     * exactly like before.
     */
    public function test_canonical_auto_wiki_rows_are_still_penalized_regardless_of_ocr_origin(): void
    {
        config([
            'kb.reranking.enabled' => true,
            'kb.canonical.priority_weight' => 0.001,
            'kb.canonical.auto_tier_penalty' => 0.02,
        ]);

        $chunks = collect([
            $this->chunk(1, canonical: true, generationSource: 'auto', ocrOrigin: false),
            $this->chunk(2, canonical: true, generationSource: 'human'),
        ]);

        $ranked = (new Reranker)->rerank('cache strategy', $chunks, limit: 10);
        $scores = $this->scoreById($ranked);
        $penalties = $this->canonicalPenaltyById($ranked);

        $this->assertGreaterThan(0.0, $penalties[1], 'a canonical auto row must still pay the auto-tier penalty even without ocr_origin');
        $this->assertGreaterThan($scores[1], $scores[2], 'a canonical human row must still outrank a canonical auto row, ocr_origin or not');
    }

    /**
     * v8.37/W3 — the pre-existing "raw" case in the firewall test above
     * (non-canonical, generation_source='human') must rank UNCHANGED by
     * this fix: autoTierPenalty('human') is 0.0 on both sides of the
     * is_canonical branch, so a non-canonical human-default row pays
     * nothing before or after — this is the "nothing re-orders but
     * unreviewed OCR text" invariant ADR 0031 §5 names explicitly.
     */
    public function test_non_canonical_human_default_rows_are_unaffected_by_the_fix(): void
    {
        config([
            'kb.reranking.enabled' => true,
            'kb.canonical.priority_weight' => 0.001,
            'kb.canonical.auto_tier_penalty' => 0.02,
        ]);

        $chunks = collect([
            $this->chunk(1, canonical: false, generationSource: 'human'),
            $this->chunk(2, canonical: false, generationSource: 'human'),
        ]);

        $ranked = (new Reranker)->rerank('cache strategy', $chunks, limit: 10);
        $scores = $this->scoreById($ranked);
        $penalties = $this->canonicalPenaltyById($ranked);

        // Copilot PR #494 — an equality-only assertion would still pass if
        // the implementation wrongly applied the SAME nonzero auto-tier
        // penalty to every non-canonical `human` row (both sides shifted by
        // the same amount tie just as well as both sides shifted by zero).
        // Pin the actual mechanism instead: rerank_detail.canonical_penalty
        // must be the known zero baseline for BOTH rows.
        $this->assertSame(0.0, $penalties[1], 'a non-canonical human-default row must pay zero auto-tier penalty');
        $this->assertSame(0.0, $penalties[2], 'a non-canonical human-default row must pay zero auto-tier penalty');
        $this->assertEqualsWithDelta($scores[1], $scores[2], 1e-9, 'two non-canonical human-default rows must still tie');
    }

    public function test_zero_penalty_disables_the_firewall(): void
    {
        config([
            'kb.reranking.enabled' => true,
            'kb.canonical.priority_weight' => 0.001,
            'kb.canonical.auto_tier_penalty' => 0.0,
        ]);

        $chunks = collect([
            $this->chunk(1, canonical: true, generationSource: 'human'),
            $this->chunk(2, canonical: true, generationSource: 'auto'),
        ]);

        $scores = $this->scoreById((new Reranker)->rerank('cache strategy', $chunks, limit: 10));

        // With the penalty off, human and auto (same priority/status) tie.
        $this->assertEqualsWithDelta($scores[1], $scores[2], 1e-9);
    }
}
