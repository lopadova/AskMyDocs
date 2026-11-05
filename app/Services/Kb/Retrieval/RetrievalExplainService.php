<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use App\Services\Kb\KbSearchService;

/**
 * v8.41/W1 (ADR 0035 §D1) — the ONE core behind the three `explain` surfaces
 * (R44): `kb:search --explain`, `GET /api/admin/kb/retrieval/explain`, and the
 * `KbRetrievalExplainTool` MCP tool. Each surface only adapts input → this
 * service → output; the tenant is whatever {@see \App\Support\TenantContext}
 * holds (set by the middleware / MCP resolver / `--tenant`).
 *
 * Runs the SAME `searchWithContext()` path the chat uses, so the trace
 * describes the real ranking, and returns numbers + short machine tokens only
 * — a 160-char chunk preview for orientation, never the full chunk text or
 * any provider payload (SEC-LLM-001 gate 8).
 */
final class RetrievalExplainService
{
    private const PREVIEW_CHARS = 160;

    public function __construct(private readonly KbSearchService $search) {}

    /** Whether the HTTP + MCP surfaces are enabled (R43). The CLI ignores it. */
    public function enabled(): bool
    {
        return (bool) config('kb.retrieval_explain.enabled', false);
    }

    public function maxLimit(): int
    {
        return max(1, (int) config('kb.retrieval_explain.max_limit', 20));
    }

    /**
     * @return array{
     *   query: string,
     *   project_key: string|null,
     *   limit: int,
     *   results: list<array<string,mixed>>,
     *   meta: array<string,mixed>
     * }
     */
    public function explain(string $query, ?string $projectKey = null, int $limit = 8, ?RetrievalFilters $filters = null, ?string $intent = null): array
    {
        $limit = max(1, min($limit, $this->maxLimit()));
        $effective = ($filters ?? RetrievalFilters::forLegacyProject($projectKey))->withExplain()->withIntent($intent);

        $result = $this->search->searchWithContext(
            query: $query,
            projectKey: $projectKey,
            limit: $limit,
            minSimilarity: (float) config('kb.default_min_similarity', 0.30),
            filters: $effective,
        );

        return [
            'query' => $query,
            'project_key' => $projectKey,
            'limit' => $limit,
            'results' => $result->primary
                ->map(fn (array $chunk): array => $this->shape($chunk))
                ->values()
                ->all(),
            'meta' => [
                'primary_count' => $result->primary->count(),
                'fusion_method' => $result->meta['search_strategy']['fusion_method'] ?? null,
                'hybrid_search' => (bool) config('kb.hybrid_search.enabled', false),
                'fts_syntax' => (string) config('kb.hybrid_search.query_syntax', 'plain'),
                'retrieval_ms' => $result->meta['retrieval_ms'] ?? null,
                // v8.43/W3 + v8.44/W4 — what the optional stages did (null = stage off).
                'cross_encoder' => $result->meta['cross_encoder'] ?? null,
                'query_expansion' => $result->meta['query_expansion'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $chunk
     * @return array<string,mixed>
     */
    private function shape(array $chunk): array
    {
        $text = (string) ($chunk['chunk_text'] ?? '');

        return [
            'chunk_id' => $chunk['chunk_id'] ?? null,
            'document' => [
                'id' => data_get($chunk, 'document.id'),
                'title' => data_get($chunk, 'document.title'),
                'source_path' => data_get($chunk, 'document.source_path'),
                'canonical_type' => data_get($chunk, 'document.canonical_type'),
                'canonical_status' => data_get($chunk, 'document.canonical_status'),
            ],
            'heading_path' => $chunk['heading_path'] ?? null,
            'preview' => mb_substr($text, 0, self::PREVIEW_CHARS),
            'vector_score' => round((float) ($chunk['vector_score'] ?? 0.0), 6),
            'rerank_score' => isset($chunk['rerank_score']) ? round((float) $chunk['rerank_score'], 6) : null,
            'explain' => $chunk['explain'] ?? null,
        ];
    }
}
