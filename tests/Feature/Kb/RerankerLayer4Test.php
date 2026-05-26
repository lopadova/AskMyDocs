<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Services\Kb\Reranker;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration test for the v4.5/W5.5 Layer-4 reranker formula.
 *
 *   score = vec·0.55 + kw·0.25 + heading·0.05
 *         + tag_overlap·0.05 + preamble·0.05
 *         + recency·0.02 + status_active·0.02
 *         + canonical_boost (when applicable)
 *
 * Each test isolates ONE additive signal: it keeps the base score
 * identical across two chunks and verifies the signal-bearing chunk
 * outranks the bare-metadata chunk.
 */
final class RerankerLayer4Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pin weights so this test is deterministic across env overrides.
        config()->set('kb.reranking.enabled', true);
        config()->set('kb.reranking.vector_weight', 0.55);
        config()->set('kb.reranking.keyword_weight', 0.25);
        config()->set('kb.reranking.heading_weight', 0.05);
        config()->set('kb.reranking.tag_overlap_weight', 0.05);
        config()->set('kb.reranking.preamble_match_weight', 0.05);
        config()->set('kb.reranking.recency_weight', 0.02);
        config()->set('kb.reranking.status_active_weight', 0.02);
    }

    /**
     * @param  array<string,mixed>  $extraMeta0  metadata for chunk 0
     * @param  array<string,mixed>  $extraMeta1  metadata for chunk 1
     */
    private function chunks(array $extraMeta0 = [], array $extraMeta1 = []): Collection
    {
        return collect([
            [
                'chunk_id' => 1,
                'chunk_text' => 'Architecture decision body.',
                'heading_path' => '',
                'vector_score' => 0.5,
                'metadata' => $extraMeta0,
            ],
            [
                'chunk_id' => 2,
                'chunk_text' => 'Architecture decision body.',
                'heading_path' => '',
                'vector_score' => 0.5,
                'metadata' => $extraMeta1,
            ],
        ]);
    }

    #[Test]
    public function tag_overlap_outranks_bare_chunk_when_query_tags_match(): void
    {
        $chunks = $this->chunks([], ['search_tags' => ['architecture', 'cache']]);

        $ranked = (new Reranker())->rerank('architecture cache policy', $chunks, 2);
        $this->assertSame(2, $ranked->first()['chunk_id']);

        $delta = $ranked->first()['rerank_score'] - $ranked->last()['rerank_score'];
        $this->assertGreaterThan(0.0, $delta);
    }

    #[Test]
    public function mention_boost_floats_mentioned_doc_to_top_without_dropping_others(): void
    {
        config()->set('kb.reranking.mention_boost_weight', 0.50);

        $chunks = collect([
            ['chunk_id' => 1, 'chunk_text' => 'body', 'heading_path' => '', 'vector_score' => 0.80, 'metadata' => [], 'document' => ['id' => 10]],
            ['chunk_id' => 2, 'chunk_text' => 'body', 'heading_path' => '', 'vector_score' => 0.50, 'metadata' => [], 'document' => ['id' => 20]],
        ]);

        // No boost → the higher-vector chunk (doc 10) ranks first.
        $plain = (new Reranker())->rerank('cache policy', $chunks, 2);
        $this->assertSame(1, $plain->first()['chunk_id']);

        // @mention doc 20 → its lower-vector chunk floats to the top…
        $boosted = (new Reranker())->rerank('cache policy', $chunks, 2, [20]);
        $this->assertSame(2, $boosted->first()['chunk_id']);
        $this->assertEqualsWithDelta(0.50, $boosted->first()['rerank_detail']['mention_boost'], 0.0001);

        // …WITHOUT excluding the non-mentioned chunk (recall preserved).
        $this->assertCount(2, $boosted);
        $this->assertEqualsWithDelta(0.0, $boosted->last()['rerank_detail']['mention_boost'], 0.0001);
    }

    #[Test]
    public function preamble_match_boosts_property_panel_chunks_only_on_property_queries(): void
    {
        // Status query → preamble chunk wins.
        $chunks = $this->chunks([], ['page_property_panel' => true]);
        $ranked = (new Reranker())->rerank("What's the status of cache?", $chunks, 2);
        $this->assertSame(2, $ranked->first()['chunk_id']);

        // Same chunks, content query → preamble doesn't help.
        $chunks2 = $this->chunks([], ['page_property_panel' => true]);
        $ranked2 = (new Reranker())->rerank('Explain the cache eviction algorithm', $chunks2, 2);
        $this->assertEqualsWithDelta(
            $ranked2->first()['rerank_score'],
            $ranked2->last()['rerank_score'],
            0.0001,
        );
    }

    #[Test]
    public function recency_boosts_this_week_over_older(): void
    {
        $chunks = $this->chunks(
            ['recency_bucket' => 'older'],
            ['recency_bucket' => 'this_week'],
        );

        $ranked = (new Reranker())->rerank('cache policy', $chunks, 2);
        $this->assertSame(2, $ranked->first()['chunk_id']);
    }

    #[Test]
    public function status_active_boosts_in_flight_chunks(): void
    {
        $chunks = $this->chunks(
            ['status_active' => false],
            ['status_active' => true],
        );

        $ranked = (new Reranker())->rerank('cache policy', $chunks, 2);
        $this->assertSame(2, $ranked->first()['chunk_id']);
    }

    #[Test]
    public function rerank_detail_carries_all_layer4_components(): void
    {
        $chunks = $this->chunks([
            'search_tags' => ['cache'],
            'recency_bucket' => 'this_week',
            'status_active' => true,
            'page_property_panel' => false,
        ]);

        $ranked = (new Reranker())->rerank('cache', $chunks->take(1), 1);
        $detail = $ranked->first()['rerank_detail'];

        $this->assertArrayHasKey('tag_overlap', $detail);
        $this->assertArrayHasKey('preamble_match', $detail);
        $this->assertArrayHasKey('recency', $detail);
        $this->assertArrayHasKey('status_active', $detail);
        $this->assertArrayHasKey('source_aware_delta', $detail);
        $this->assertGreaterThan(0.0, $detail['source_aware_delta']);
    }

    #[Test]
    public function legacy_chunk_without_source_aware_metadata_takes_no_boost(): void
    {
        // Pre-v4.5 chunks have no metadata.search_tags / recency_bucket /
        // status_active / page_property_panel. The Reranker must leave
        // them at the pre-W5.5 score (= base score + canonical_boost,
        // 0 source-aware delta).
        $chunks = collect([
            [
                'chunk_id' => 99,
                'chunk_text' => 'legacy text',
                'heading_path' => '',
                'vector_score' => 0.5,
                'metadata' => [],
            ],
        ]);

        $ranked = (new Reranker())->rerank('legacy text', $chunks, 1);
        $detail = $ranked->first()['rerank_detail'];
        $this->assertSame(0.0, $detail['source_aware_delta']);
    }
}
