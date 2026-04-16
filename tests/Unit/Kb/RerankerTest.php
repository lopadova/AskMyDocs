<?php

namespace Tests\Unit\Kb;

use App\Services\Kb\Reranker;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RerankerTest extends TestCase
{
    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'chunk_id' => 1,
            'chunk_text' => 'some body text',
            'heading_path' => 'Section > Sub',
            'vector_score' => 0.5,
        ], $overrides);
    }

    public function test_empty_input_returns_empty(): void
    {
        $out = (new Reranker())->rerank('any', collect(), 5);

        $this->assertInstanceOf(Collection::class, $out);
        $this->assertTrue($out->isEmpty());
    }

    public function test_truncates_to_limit_when_tokenization_empty(): void
    {
        // query made of stop-words only → tokenize returns empty → take($limit)
        $chunks = collect([
            $this->candidate(['chunk_id' => 1]),
            $this->candidate(['chunk_id' => 2]),
            $this->candidate(['chunk_id' => 3]),
        ]);

        $out = (new Reranker())->rerank('il la un a di', $chunks, 2);

        $this->assertCount(2, $out);
        $this->assertSame([1, 2], $out->pluck('chunk_id')->all());
    }

    public function test_disabled_reranking_returns_first_n(): void
    {
        config()->set('kb.reranking.enabled', false);

        $chunks = collect([
            $this->candidate(['chunk_id' => 1, 'vector_score' => 0.1]),
            $this->candidate(['chunk_id' => 2, 'vector_score' => 0.9]),
            $this->candidate(['chunk_id' => 3, 'vector_score' => 0.5]),
        ]);

        $out = (new Reranker())->rerank('OAuth', $chunks, 2);

        // no reordering, no scoring
        $this->assertSame([1, 2], $out->pluck('chunk_id')->all());
        $this->assertArrayNotHasKey('rerank_score', $out->first());
    }

    public function test_keyword_match_promotes_chunks_above_higher_vector_only(): void
    {
        config()->set('kb.reranking.enabled', true);
        config()->set('kb.reranking.vector_weight', 0.6);
        config()->set('kb.reranking.keyword_weight', 0.3);
        config()->set('kb.reranking.heading_weight', 0.1);

        $chunks = collect([
            // chunk A: higher vector, no keyword
            [
                'chunk_id' => 'A',
                'chunk_text' => 'something unrelated about birds',
                'heading_path' => 'Nature > Birds',
                'vector_score' => 0.80,
            ],
            // chunk B: lower vector, but exact keyword match
            [
                'chunk_id' => 'B',
                'chunk_text' => 'configurazione OAuth 2.0 per enterprise',
                'heading_path' => 'Autenticazione > OAuth',
                'vector_score' => 0.55,
            ],
        ]);

        $out = (new Reranker())->rerank('configurazione OAuth', $chunks, 2);

        $this->assertSame('B', $out->first()['chunk_id']);
        $this->assertGreaterThan(
            $out->last()['rerank_score'],
            $out->first()['rerank_score'],
        );
    }

    public function test_attaches_detailed_score_breakdown(): void
    {
        config()->set('kb.reranking.enabled', true);

        $chunks = collect([
            $this->candidate(['chunk_text' => 'OAuth config details', 'heading_path' => 'OAuth']),
        ]);

        $out = (new Reranker())->rerank('OAuth', $chunks, 1);

        $first = $out->first();
        $this->assertArrayHasKey('rerank_score', $first);
        $this->assertArrayHasKey('rerank_detail', $first);
        $this->assertArrayHasKey('vector', $first['rerank_detail']);
        $this->assertArrayHasKey('keyword', $first['rerank_detail']);
        $this->assertArrayHasKey('heading', $first['rerank_detail']);
        $this->assertArrayHasKey('combined', $first['rerank_detail']);
    }

    public function test_respects_limit(): void
    {
        config()->set('kb.reranking.enabled', true);

        $chunks = collect([
            $this->candidate(['chunk_id' => 1]),
            $this->candidate(['chunk_id' => 2]),
            $this->candidate(['chunk_id' => 3]),
            $this->candidate(['chunk_id' => 4]),
        ]);

        $out = (new Reranker())->rerank('OAuth', $chunks, 2);

        $this->assertCount(2, $out);
    }

    public function test_results_sorted_desc_by_rerank_score(): void
    {
        config()->set('kb.reranking.enabled', true);

        $chunks = collect([
            ['chunk_id' => 1, 'chunk_text' => 'no match',       'heading_path' => '', 'vector_score' => 0.3],
            ['chunk_id' => 2, 'chunk_text' => 'OAuth token',    'heading_path' => 'OAuth', 'vector_score' => 0.6],
            ['chunk_id' => 3, 'chunk_text' => 'some oauth hint','heading_path' => '', 'vector_score' => 0.4],
        ]);

        $out = (new Reranker())->rerank('OAuth', $chunks, 3)->values();

        $scores = $out->pluck('rerank_score')->all();
        $sortedDesc = $scores;
        rsort($sortedDesc);
        $this->assertSame($sortedDesc, $scores);
    }

    public function test_keyword_score_is_bounded_at_one(): void
    {
        config()->set('kb.reranking.enabled', true);
        config()->set('kb.reranking.vector_weight', 0.0);
        config()->set('kb.reranking.keyword_weight', 1.0);
        config()->set('kb.reranking.heading_weight', 0.0);

        $chunks = collect([
            [
                'chunk_id' => 1,
                'chunk_text' => 'OAuth OAuth OAuth token token token',
                'heading_path' => '',
                'vector_score' => 0.0,
            ],
        ]);

        $out = (new Reranker())->rerank('OAuth token', $chunks, 1);

        $this->assertLessThanOrEqual(1.0, $out->first()['rerank_score']);
    }

    public function test_handles_missing_heading_and_vector_score(): void
    {
        config()->set('kb.reranking.enabled', true);

        $chunks = collect([
            ['chunk_id' => 1, 'chunk_text' => 'OAuth setup guide'], // no heading, no vector
        ]);

        $out = (new Reranker())->rerank('OAuth', $chunks, 1);

        $this->assertCount(1, $out);
        $this->assertSame(0.0, $out->first()['rerank_detail']['vector']);
        $this->assertSame(0.0, $out->first()['rerank_detail']['heading']);
    }

    public function test_stop_words_are_filtered_from_query(): void
    {
        config()->set('kb.reranking.enabled', true);
        config()->set('kb.reranking.vector_weight', 0.0);
        config()->set('kb.reranking.keyword_weight', 1.0);
        config()->set('kb.reranking.heading_weight', 0.0);

        // 'come' and 'il' are Italian stop words → only 'oauth' should count
        $chunks = collect([
            ['chunk_id' => 1, 'chunk_text' => 'OAuth details', 'heading_path' => '', 'vector_score' => 0.0],
        ]);

        $out = (new Reranker())->rerank('come il OAuth', $chunks, 1);

        // Since only 'oauth' is a real token and it matches, coverage should be 1.0 (plus bonus)
        $this->assertSame(1.0, $out->first()['rerank_detail']['keyword']);
    }
}
