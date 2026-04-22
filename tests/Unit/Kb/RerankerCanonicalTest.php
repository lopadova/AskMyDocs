<?php

namespace Tests\Unit\Kb;

use App\Services\Kb\Reranker;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Regression suite for the canonical boost / penalty the Reranker applies
 * on top of its existing vector+keyword+heading fusion.
 */
class RerankerCanonicalTest extends TestCase
{
    public function test_canonical_accepted_outranks_non_canonical_with_same_vector(): void
    {
        $chunks = collect([
            $this->chunk('plain body', vector: 0.80, canonical: false),
            $this->chunk('canonical body', vector: 0.80, canonical: true, status: 'accepted', priority: 90),
        ]);

        $result = (new Reranker())->rerank('query keyword', $chunks, 2);

        $this->assertTrue($result->first()['document']['is_canonical']);
    }

    public function test_superseded_is_penalized_below_non_canonical(): void
    {
        // non-canonical at 0.80 vector vs superseded canonical at 0.80 vector.
        // Superseded penalty (0.40) pulls the canonical below.
        $chunks = collect([
            $this->chunk('plain body', vector: 0.80, canonical: false),
            $this->chunk('superseded', vector: 0.80, canonical: true, status: 'superseded', priority: 90),
        ]);

        $result = (new Reranker())->rerank('query', $chunks, 2);

        $this->assertFalse($result->first()['document']['is_canonical']);
        $this->assertSame('superseded', $result->last()['document']['canonical_status']);
    }

    public function test_deprecated_applies_the_deprecated_penalty(): void
    {
        $chunks = collect([
            $this->chunk('a', vector: 0.70, canonical: true, status: 'accepted', priority: 50),
            $this->chunk('b', vector: 0.70, canonical: true, status: 'deprecated', priority: 100),
        ]);

        $result = (new Reranker())->rerank('query', $chunks, 2);

        $this->assertSame('accepted', $result->first()['document']['canonical_status']);
    }

    public function test_archived_has_the_strongest_penalty(): void
    {
        $chunks = collect([
            $this->chunk('a', vector: 0.50, canonical: false),
            $this->chunk('b', vector: 0.90, canonical: true, status: 'archived', priority: 100),
        ]);

        $result = (new Reranker())->rerank('query', $chunks, 2);

        $this->assertFalse($result->first()['document']['is_canonical']);
    }

    public function test_rerank_detail_exposes_canonical_boost_and_penalty(): void
    {
        $chunks = collect([
            $this->chunk('a', vector: 0.5, canonical: true, status: 'accepted', priority: 80),
        ]);

        $result = (new Reranker())->rerank('query', $chunks, 1);
        $detail = $result->first()['rerank_detail'];

        $this->assertArrayHasKey('canonical_boost', $detail);
        $this->assertArrayHasKey('canonical_penalty', $detail);
        $this->assertArrayHasKey('base', $detail);
        $this->assertGreaterThan(0.0, $detail['canonical_boost']);
        $this->assertSame(0.0, $detail['canonical_penalty']);
    }

    public function test_retrieval_priority_tunes_the_boost_magnitude(): void
    {
        // Same status, same vector score, different priorities.
        // Doc with priority 100 must outrank doc with priority 10.
        $chunks = collect([
            $this->chunk('low', vector: 0.70, canonical: true, status: 'accepted', priority: 10),
            $this->chunk('hi', vector: 0.70, canonical: true, status: 'accepted', priority: 100),
        ]);

        $result = (new Reranker())->rerank('query', $chunks, 2);

        $this->assertSame('hi', $result->first()['chunk_text']);
    }

    public function test_non_canonical_chunks_keep_legacy_behaviour(): void
    {
        // Two non-canonical chunks with distinct vector scores — ordering
        // should be by vector exclusively (no canonical adjustment).
        $chunks = collect([
            $this->chunk('lower', vector: 0.50, canonical: false),
            $this->chunk('higher', vector: 0.80, canonical: false),
        ]);

        $result = (new Reranker())->rerank('query', $chunks, 2);

        $this->assertSame('higher', $result->first()['chunk_text']);
        $detail = $result->first()['rerank_detail'];
        $this->assertSame(0.0, $detail['canonical_boost']);
        $this->assertSame(0.0, $detail['canonical_penalty']);
    }

    private function chunk(
        string $text,
        float $vector,
        bool $canonical,
        string $status = '',
        int $priority = 50,
    ): array {
        return [
            'chunk_id' => random_int(1, 1_000_000),
            'project_key' => 'acme',
            'heading_path' => null,
            'chunk_text' => $text,
            'metadata' => [],
            'vector_score' => $vector,
            'document' => [
                'id' => 1,
                'title' => $text,
                'source_path' => 'p.md',
                'source_type' => 'markdown',
                'is_canonical' => $canonical,
                'canonical_status' => $canonical ? $status : null,
                'canonical_type' => $canonical ? 'decision' : null,
                'retrieval_priority' => $priority,
                'slug' => $canonical ? 'dec-'.$text : null,
                'doc_id' => $canonical ? 'DEC-1' : null,
            ],
        ];
    }
}
