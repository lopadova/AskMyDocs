<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Retrieval;

use App\Models\KnowledgeChunk;
use App\Services\Kb\Retrieval\RetrievalExplainPresenter;
use App\Services\Kb\Retrieval\RetrievalExplainTrace;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * v8.41/W1 (ADR 0035 §D1) — the trace must reproduce the fusion arithmetic of
 * KbSearchService::reciprocalRankFusion(), otherwise an "explanation" would be
 * a story rather than the ranking.
 */
final class RetrievalExplainTraceTest extends TestCase
{
    /** @return Collection<int, KnowledgeChunk> */
    private function chunks(array $idsAndScores, string $scoreField): Collection
    {
        return collect($idsAndScores)->map(function (float $score, int $id) use ($scoreField): KnowledgeChunk {
            $c = new KnowledgeChunk();
            $c->id = $id;
            $c->{$scoreField} = $score;

            return $c;
        })->values();
    }

    public function test_hybrid_contributions_sum_to_the_fused_score_per_chunk(): void
    {
        $semantic = $this->chunks([10 => 0.9, 11 => 0.8, 12 => 0.7], 'vector_score');
        $fts = $this->chunks([12 => 0.5, 10 => 0.4], 'fts_score');

        $trace = RetrievalExplainTrace::fromLists($semantic, $fts, 60, 0.7, 0.3);

        // chunk 10: vector rank 1, fts rank 2
        $expected10 = 0.7 / (60 + 1) + 0.3 / (60 + 2);
        $this->assertEqualsWithDelta($expected10, $trace->rrfFor(10)['total'], 1e-7);
        $this->assertCount(2, $trace->listsFor(10));

        // chunk 11: vector only, rank 2
        $this->assertEqualsWithDelta(0.7 / (60 + 2), $trace->rrfFor(11)['total'], 1e-7);
        $this->assertCount(1, $trace->listsFor(11));

        // chunk 12: vector rank 3, fts rank 1
        $this->assertEqualsWithDelta(0.7 / (60 + 3) + 0.3 / (60 + 1), $trace->rrfFor(12)['total'], 1e-7);

        // Strictly monotonic fixture (R16): the fused order is 10 > 12 > 11.
        $order = collect([10, 11, 12])->sortByDesc(fn (int $id): float => $trace->rrfFor($id)['total'])->values()->all();
        $this->assertSame([10, 12, 11], $order);
    }

    public function test_each_list_entry_records_source_rank_weight_and_backend_score(): void
    {
        $trace = RetrievalExplainTrace::fromLists(
            $this->chunks([5 => 0.91], 'vector_score'),
            $this->chunks([5 => 0.33], 'fts_score'),
            60,
            0.7,
            0.3,
        );

        [$vec, $fts] = $trace->listsFor(5);

        $this->assertSame('vector', $vec['source']);
        $this->assertSame(1, $vec['rank']);
        $this->assertEqualsWithDelta(0.7, $vec['weight'], 1e-9);
        $this->assertEqualsWithDelta(0.91, $vec['backend_score'], 1e-9);
        $this->assertSame('fts', $fts['source']);
        $this->assertEqualsWithDelta(0.33, $fts['backend_score'], 1e-9);
    }

    public function test_vector_only_mode_has_no_rrf_and_no_rrf_contribution(): void
    {
        $trace = RetrievalExplainTrace::fromLists($this->chunks([7 => 0.6], 'vector_score'), null, 60, 0.7, 0.3);

        $this->assertNull($trace->rrfFor(7));
        $this->assertCount(1, $trace->listsFor(7));
        $this->assertNull($trace->listsFor(7)[0]['rrf_contribution']);
        $this->assertEqualsWithDelta(1.0, $trace->listsFor(7)[0]['weight'], 1e-9);
    }

    public function test_unknown_chunk_yields_empty_lists_and_null_rrf(): void
    {
        $trace = RetrievalExplainTrace::fromLists(collect(), null, 60, 0.7, 0.3);

        $this->assertSame([], $trace->listsFor(999));
        $this->assertNull($trace->rrfFor(999));
    }

    public function test_presenter_maps_rerank_detail_and_reserves_later_workstream_keys(): void
    {
        $trace = RetrievalExplainTrace::fromLists($this->chunks([3 => 0.5], 'vector_score'), null, 60, 0.7, 0.3);

        $out = RetrievalExplainPresenter::present([
            'chunk_id' => 3,
            'rerank_detail' => [
                'vector' => 0.5, 'keyword' => 0.25, 'heading' => 0.0, 'tag_overlap' => 0.1,
                'recency' => 0.02, 'preamble_match' => 0.0, 'canonical_boost' => 0.27,
                'canonical_penalty' => 0.4, 'mention_boost' => 0.0, 'combined' => 0.41,
            ],
        ], $trace);

        $this->assertSame(0.4, $out['heuristic']['status_penalty']);
        $this->assertSame(0.27, $out['heuristic']['canonical_boost']);
        $this->assertSame(0.41, $out['heuristic']['total']);
        $this->assertSame(0.1, $out['heuristic']['tags']);
        // Reserved keys are present so the contract is stable (R27), null until filled.
        $this->assertArrayHasKey('cross_encoder', $out);
        $this->assertNull($out['cross_encoder']);
        $this->assertArrayHasKey('graph', $out);
        $this->assertNull($out['graph']);
    }

    public function test_presenter_without_a_reranker_detail_reports_null_heuristic_not_zeros(): void
    {
        $out = RetrievalExplainPresenter::present(['chunk_id' => 1], null);

        $this->assertNull($out['heuristic']);
        $this->assertSame([], $out['lists']);
    }
}
