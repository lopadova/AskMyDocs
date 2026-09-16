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
    private function chunk(int $id, bool $canonical, string $generationSource): array
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
            ],
        ];
    }

    private function scoreById(Collection $ranked): array
    {
        return $ranked->mapWithKeys(fn (array $c) => [(int) $c['chunk_id'] => (float) $c['rerank_score']])->all();
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
     */
    public function test_reviewed_scan_outranks_unreviewed_scan_when_both_are_non_canonical(): void
    {
        config([
            'kb.reranking.enabled' => true,
            'kb.canonical.priority_weight' => 0.001,
            'kb.canonical.auto_tier_penalty' => 0.02,
        ]);

        $chunks = collect([
            $this->chunk(1, canonical: false, generationSource: 'auto'),
            $this->chunk(2, canonical: false, generationSource: 'human'),
        ]);

        $ranked = (new Reranker)->rerank('cache strategy', $chunks, limit: 10);
        $scores = $this->scoreById($ranked);

        $this->assertGreaterThan($scores[1], $scores[2], 'a reviewed (human) non-canonical scan must outrank an unreviewed (auto) one');
        $this->assertSame([2, 1], $ranked->pluck('chunk_id')->map('intval')->all());
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

        $scores = $this->scoreById((new Reranker)->rerank('cache strategy', $chunks, limit: 10));

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
