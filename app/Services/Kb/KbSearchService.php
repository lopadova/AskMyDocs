<?php

namespace App\Services\Kb;

use App\Models\KnowledgeChunk;
use App\Services\Kb\Retrieval\GraphExpander;
use App\Services\Kb\Retrieval\RejectedApproachInjector;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KbSearchService
{
    private readonly GraphExpander $graphExpander;
    private readonly RejectedApproachInjector $rejectedInjector;

    public function __construct(
        private readonly EmbeddingCacheService $embeddingCache,
        private readonly Reranker $reranker,
        ?GraphExpander $graphExpander = null,
        ?RejectedApproachInjector $rejectedInjector = null,
    ) {
        // GraphExpander and RejectedApproachInjector are default-constructed
        // when not wired explicitly so legacy resolutions of KbSearchService
        // (tests / older bindings) keep working without signature churn.
        $this->graphExpander = $graphExpander ?? new GraphExpander();
        $this->rejectedInjector = $rejectedInjector ?? new RejectedApproachInjector($this->embeddingCache);
    }

    /**
     * Extended search that adds graph expansion + rejected-approach
     * injection on top of the base primary results. Used by the chat
     * controller and MCP tools that want the full retrieval context;
     * the plain {@see search()} stays as-is for backwards compatibility.
     */
    public function searchWithContext(
        string $query,
        ?string $projectKey = null,
        int $limit = 8,
        float $minSimilarity = 0.30,
    ): SearchResult {
        $primary = $this->search($query, $projectKey, $limit, $minSimilarity);
        $expanded = $this->graphExpander->expand($primary, $projectKey);
        $rejected = $this->rejectedInjector->pick($query, $projectKey);

        return new SearchResult(
            primary: $primary,
            expanded: $expanded,
            rejected: $rejected,
            meta: [
                'primary_count' => $primary->count(),
                'expanded_count' => $expanded->count(),
                'rejected_count' => $rejected->count(),
                'project_key' => $projectKey,
            ],
        );
    }

    /**
     * Hybrid search: semantic (pgvector) + optional full-text (tsvector) + reranking.
     *
     * 1. Generate query embedding (with cache)
     * 2. pgvector cosine similarity (over-retrieve if reranking enabled)
     * 3. Optional: PostgreSQL full-text search merged via RRF
     * 4. Reranker fuses vector + keyword + heading scores
     * 5. Return top-K results
     */
    public function search(
        string $query,
        ?string $projectKey = null,
        int $limit = 8,
        float $minSimilarity = 0.30,
    ): Collection {
        // Generate query embedding (cached)
        $embeddingsResponse = $this->embeddingCache->generate([$query]);
        $queryEmbedding = $embeddingsResponse->embeddings[0];
        $vectorString = '[' . implode(',', $queryEmbedding) . ']';

        // Over-retrieve for reranking
        $rerankEnabled = config('kb.reranking.enabled', true);
        $candidateCount = $rerankEnabled
            ? $limit * (int) config('kb.reranking.candidate_multiplier', 3)
            : $limit;

        // ── Semantic search (pgvector) ───────────────────────────
        $builder = KnowledgeChunk::query()
            ->with('document')
            ->selectRaw('knowledge_chunks.*, (1 - (embedding <=> ?::vector)) as vector_score', [$vectorString])
            ->whereRaw('(1 - (embedding <=> ?::vector)) >= ?', [$vectorString, $minSimilarity])
            ->whereHas('document', fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderByDesc('vector_score');

        if ($projectKey !== null && $projectKey !== '') {
            $builder->where('project_key', $projectKey);
        }

        $semanticChunks = $builder->limit($candidateCount)->get();

        // ── Hybrid: merge full-text results if enabled ───────────
        $hybridEnabled = config('kb.hybrid_search.enabled', false);

        if ($hybridEnabled) {
            $ftsChunks = $this->fullTextSearch($query, $projectKey, $candidateCount);

            // Merge via Reciprocal Rank Fusion (RRF)
            $semanticChunks = $this->reciprocalRankFusion(
                $semanticChunks,
                $ftsChunks,
                config('kb.hybrid_search.rrf_k', 60),
                config('kb.hybrid_search.semantic_weight', 0.7),
                config('kb.hybrid_search.fts_weight', 0.3),
            );
        }

        // ── Map to array format ──────────────────────────────────
        $chunks = collect($semanticChunks)->map(function ($chunk): array {
            return [
                'chunk_id' => $chunk->id,
                'project_key' => $chunk->project_key,
                'heading_path' => $chunk->heading_path,
                'chunk_text' => $chunk->chunk_text,
                'metadata' => $chunk->metadata ?? [],
                'vector_score' => (float) ($chunk->vector_score ?? $chunk->rrf_score ?? 0),
                'document' => [
                    'id' => $chunk->document?->id,
                    'title' => $chunk->document?->title,
                    'source_path' => $chunk->document?->source_path,
                    'source_type' => $chunk->document?->source_type,
                    // Canonical fields: consumed by Reranker (priority boost +
                    // status penalty) and by GraphExpander (seed node slugs).
                    'doc_id' => $chunk->document?->doc_id,
                    'slug' => $chunk->document?->slug,
                    'is_canonical' => (bool) ($chunk->document?->is_canonical ?? false),
                    'canonical_type' => $chunk->document?->canonical_type,
                    'canonical_status' => $chunk->document?->canonical_status,
                    'retrieval_priority' => (int) ($chunk->document?->retrieval_priority ?? 50),
                ],
            ];
        });

        return $this->reranker->rerank($query, $chunks, $limit);
    }

    /**
     * PostgreSQL full-text search using ts_rank.
     *
     * Uses plainto_tsquery for safe query parsing (no syntax errors).
     * Searches chunk_text with the configured FTS language.
     */
    private function fullTextSearch(string $query, ?string $projectKey, int $limit): Collection
    {
        $lang = config('kb.hybrid_search.fts_language', 'italian');

        $builder = KnowledgeChunk::query()
            ->with('document')
            ->selectRaw(
                "knowledge_chunks.*, ts_rank(to_tsvector(?, chunk_text), plainto_tsquery(?, ?)) as fts_score",
                [$lang, $lang, $query]
            )
            ->whereRaw(
                "to_tsvector(?, chunk_text) @@ plainto_tsquery(?, ?)",
                [$lang, $lang, $query]
            )
            ->whereHas('document', fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderByDesc('fts_score');

        if ($projectKey !== null && $projectKey !== '') {
            $builder->where('project_key', $projectKey);
        }

        return $builder->limit($limit)->get();
    }

    /**
     * Reciprocal Rank Fusion (RRF) to merge two ranked lists.
     *
     * RRF score = weight / (k + rank)
     *
     * Chunks appearing in both lists get summed scores.
     * Returns a merged collection sorted by RRF score descending.
     */
    private function reciprocalRankFusion(
        Collection $semanticResults,
        Collection $ftsResults,
        int $k,
        float $semanticWeight,
        float $ftsWeight,
    ): Collection {
        $scores = [];
        $chunks = [];

        // Score semantic results
        foreach ($semanticResults->values() as $rank => $chunk) {
            $id = $chunk->id;
            $scores[$id] = ($scores[$id] ?? 0) + $semanticWeight / ($k + $rank + 1);
            $chunks[$id] = $chunk;
        }

        // Score FTS results
        foreach ($ftsResults->values() as $rank => $chunk) {
            $id = $chunk->id;
            $scores[$id] = ($scores[$id] ?? 0) + $ftsWeight / ($k + $rank + 1);
            if (! isset($chunks[$id])) {
                $chunks[$id] = $chunk;
            }
        }

        // Sort by RRF score
        arsort($scores);

        // Assign rrf_score to chunks and return
        $merged = collect();
        foreach ($scores as $id => $score) {
            $chunk = $chunks[$id];
            $chunk->rrf_score = $score;
            // Preserve vector_score if available, else use rrf_score
            if (! isset($chunk->vector_score) || $chunk->vector_score === null) {
                $chunk->vector_score = $score;
            }
            $merged->push($chunk);
        }

        return $merged;
    }
}
