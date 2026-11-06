<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use Illuminate\Support\Collection;

/**
 * v8.41/W1 (ADR 0035 §D1) — records, per chunk, which ranked lists produced
 * it and with what contribution, while `KbSearchService::search()` fuses the
 * vector and full-text candidates. Built ONLY when the caller asked for an
 * explanation (`RetrievalFilters::$explain`); otherwise the service never
 * instantiates it, so the hot path pays nothing.
 *
 * The arithmetic mirrors `KbSearchService::reciprocalRankFusion()` exactly
 * (rank is 1-based here, so `weight / (k + rank)` equals the service's
 * `weight / (k + zeroBasedRank + 1)`), which is what lets a test assert that
 * the listed contributions sum to the fused score.
 */
final class RetrievalExplainTrace
{
    /** @var array<int, list<array<string,mixed>>> chunk id → list contributions */
    private array $lists = [];

    /** @var array<int, array{base: float, top_rank_bonus: float, total: float}> chunk id → fused score (hybrid only) */
    private array $rrf = [];

    /**
     * @param  Collection<int, \App\Models\KnowledgeChunk>  $semantic  vector candidates, best first
     * @param  Collection<int, \App\Models\KnowledgeChunk>|null  $fts  full-text candidates, best first; null = hybrid off
     */
    public static function fromLists(
        Collection $semantic,
        ?Collection $fts,
        int $k,
        float $semanticWeight,
        float $ftsWeight,
    ): self {
        $trace = new self();
        $fused = $fts !== null;

        foreach ($semantic->values() as $i => $chunk) {
            $rank = $i + 1;
            $weight = $fused ? $semanticWeight : 1.0;
            $trace->add((int) $chunk->id, [
                'source' => 'vector',
                'query' => 'original',
                'rank' => $rank,
                'weight' => $weight,
                'backend_score' => round((float) ($chunk->vector_score ?? 0.0), 6),
                'rrf_contribution' => $fused ? round($weight / ($k + $rank), 8) : null,
            ]);
        }

        foreach (($fts ?? collect())->values() as $i => $chunk) {
            $rank = $i + 1;
            $trace->add((int) $chunk->id, [
                'source' => 'fts',
                'query' => 'original',
                'rank' => $rank,
                'weight' => $ftsWeight,
                'backend_score' => round((float) ($chunk->fts_score ?? 0.0), 6),
                'rrf_contribution' => round($ftsWeight / ($k + $rank), 8),
            ]);
        }

        if ($fused) {
            $trace->totalise();
        }

        return $trace;
    }

    /**
     * N-list form (v8.44/W4, query expansion): every ranked list — the original
     * query's vector / full-text lists AND each `lex` / `vec` / `hyde` expansion
     * list — contributes `weight / (k + rank)`; the contributions sum to the
     * fused score exactly like {@see fromLists()}.
     *
     * @param  list<array{source: string, query: string, chunks: Collection<int, \App\Models\KnowledgeChunk>, weight: float, score_field: string}>  $lists
     */
    public static function fromNamedLists(array $lists, int $k): self
    {
        $trace = new self();

        foreach ($lists as $list) {
            foreach ($list['chunks']->values() as $i => $chunk) {
                $rank = $i + 1;
                $trace->add((int) $chunk->id, [
                    'source' => $list['source'],
                    'query' => $list['query'],
                    'rank' => $rank,
                    'weight' => round($list['weight'], 6),
                    'backend_score' => round((float) ($chunk->{$list['score_field']} ?? 0.0), 6),
                    'rrf_contribution' => round($list['weight'] / ($k + $rank), 8),
                ]);
            }
        }

        $trace->totalise();

        return $trace;
    }

    /** @return list<array<string,mixed>> */
    public function listsFor(int $chunkId): array
    {
        return $this->lists[$chunkId] ?? [];
    }

    /** @return array{base: float, top_rank_bonus: float, total: float}|null */
    public function rrfFor(int $chunkId): ?array
    {
        return $this->rrf[$chunkId] ?? null;
    }

    /**
     * `base` = Σ list contributions; `total` adds the W6 top-rank bonus
     * ({@see TopRankBonus}, original-query lists only) — exactly what the
     * service fuses, so `total` is the chunk's `rrf_score`.
     */
    private function totalise(): void
    {
        foreach ($this->lists as $id => $contribs) {
            $sum = array_sum(array_map(static fn (array $c): float => (float) $c['rrf_contribution'], $contribs));
            $originalRanks = array_map(
                static fn (array $c): int => (int) $c['rank'],
                array_filter($contribs, static fn (array $c): bool => ($c['query'] ?? null) === 'original'),
            );
            $bonus = TopRankBonus::for($originalRanks === [] ? null : min($originalRanks));
            $this->rrf[$id] = ['base' => round($sum, 8), 'top_rank_bonus' => round($bonus, 8), 'total' => round($sum + $bonus, 8)];
        }
    }

    /** @param  array<string,mixed>  $contribution */
    private function add(int $chunkId, array $contribution): void
    {
        $this->lists[$chunkId][] = $contribution;
    }
}
