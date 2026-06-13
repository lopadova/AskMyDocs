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
