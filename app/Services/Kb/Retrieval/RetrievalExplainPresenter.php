<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

/**
 * v8.41/W1 (ADR 0035 §D1) — shapes the per-result `explain` block from the
 * values the pipeline already computed:
 *
 *   - `lists`     from {@see RetrievalExplainTrace} (vector / fts contributions);
 *   - `rrf`       the fused score when hybrid search was on, else null;
 *   - `heuristic` from `Reranker`'s `rerank_detail` (null when reranking is off);
 *   - `cross_encoder`, `graph` reserved keys (filled by later workstreams, null
 *     until then — present so the contract is stable, R27).
 *
 * Never carries chunk text or provider payloads (SEC-LLM-001 gate 8): only
 * numbers and short machine tokens.
 */
final class RetrievalExplainPresenter
{
    /**
     * @param  array<string,mixed>  $chunk  a candidate chunk array after reranking
     * @return array<string,mixed>
     */
    public static function present(array $chunk, ?RetrievalExplainTrace $trace): array
    {
        $chunkId = (int) ($chunk['chunk_id'] ?? 0);
        $detail = $chunk['rerank_detail'] ?? null;

        return [
            'lists' => $trace?->listsFor($chunkId) ?? [],
            'rrf' => $trace?->rrfFor($chunkId),
            'heuristic' => is_array($detail) ? self::heuristic($detail) : null,
            'cross_encoder' => $chunk['cross_encoder'] ?? null,
            'graph' => $chunk['graph'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private static function heuristic(array $detail): array
    {
        return [
            'vector' => $detail['vector'] ?? 0.0,
            'keyword' => $detail['keyword'] ?? 0.0,
            'heading' => $detail['heading'] ?? 0.0,
            'tags' => $detail['tag_overlap'] ?? 0.0,
            'recency' => $detail['recency'] ?? 0.0,
            'preamble' => $detail['preamble_match'] ?? 0.0,
            'canonical_boost' => $detail['canonical_boost'] ?? 0.0,
            'status_penalty' => $detail['canonical_penalty'] ?? 0.0,
            'mention_boost' => $detail['mention_boost'] ?? 0.0,
            'total' => $detail['combined'] ?? 0.0,
        ];
    }
}
