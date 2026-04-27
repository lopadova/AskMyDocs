<?php

namespace App\Services\Kb;

use App\Models\KnowledgeChunk;
use App\Services\Kb\Retrieval\GraphExpander;
use App\Services\Kb\Retrieval\RejectedApproachInjector;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Database\Eloquent\Builder;
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
        ?RetrievalFilters $filters = null,
    ): SearchResult {
        $effectiveFilters = $filters ?? RetrievalFilters::forLegacyProject($projectKey);
        // Resolve a representative project_key for the legacy meta payload
        // and for the graph expander / rejected injector (which still take
        // a single ?string $projectKey today — extending them to filters
        // is a v3.1 follow-up).
        $effectiveProject = $projectKey
            ?? ($effectiveFilters->projectKeys[0] ?? null);

        $primary = $this->search($query, $projectKey, $limit, $minSimilarity, $filters);
        $expanded = $this->graphExpander->expand($primary, $effectiveProject);
        $rejected = $this->rejectedInjector->pick($query, $effectiveProject);

        return new SearchResult(
            primary: $primary,
            expanded: $expanded,
            rejected: $rejected,
            meta: [
                'primary_count' => $primary->count(),
                'expanded_count' => $expanded->count(),
                'rejected_count' => $rejected->count(),
                'project_key' => $effectiveProject,
                // Operational hint: how many filter dimensions the USER
                // selected on this query — NOT how many actually narrowed
                // the candidate set (some dimensions like `tagSlugs`,
                // `folderGlobs`, `connectorTypes` are accepted in the DTO
                // but no-op in applyFilters() until their implementing
                // task lands; counting them as "active" would overstate
                // narrowing and confuse the empty-result correlation in
                // admin telemetry). UI (T3.x) surfaces "5 filters
                // selected" in the chat composer; the count grows
                // organically as T2.3/T2.4 etc. wire up the deferred
                // dimensions.
                'filters_selected' => $effectiveFilters->isEmpty()
                    ? 0
                    : count(array_filter([
                        $effectiveFilters->projectKeys !== [],
                        $effectiveFilters->tagSlugs !== [],
                        $effectiveFilters->sourceTypes !== [],
                        $effectiveFilters->canonicalTypes !== [],
                        $effectiveFilters->connectorTypes !== [],
                        $effectiveFilters->docIds !== [],
                        $effectiveFilters->folderGlobs !== [],
                        $effectiveFilters->languages !== [],
                        $effectiveFilters->dateFrom !== null,
                        $effectiveFilters->dateTo !== null,
                    ])),
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
        ?RetrievalFilters $filters = null,
    ): Collection {
        // T2.1 — back-compat: legacy callers pass `?string $projectKey`;
        // when no filters DTO is provided, we synthesise one from the
        // legacy parameter so applyFilters() handles BOTH paths uniformly.
        // When the caller passes BOTH (rare), filters wins for everything
        // except the chunk-level `project_key` which still gets the
        // legacy single-project filter (back-compat with the existing
        // builder shape — the filters DTO's projectKeys narrows DOCUMENTS,
        // the chunk-level filter narrows CHUNKS).
        $effectiveFilters = $filters ?? RetrievalFilters::forLegacyProject($projectKey);

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

        if (! $effectiveFilters->isEmpty()) {
            $this->applyFilters($builder, $effectiveFilters);
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

    /**
     * Threads RetrievalFilters into the chunk-search query (T2.1 scaffold).
     *
     * Filters constrain the candidate document population BEFORE the
     * reranker scores chunks; this is intentionally placed at the WHERE
     * level (not in the reranker) so over-retrieval doesn't waste
     * embedding-cost on documents the user has already excluded.
     *
     * Each clause is opt-in via the corresponding DTO field being non-empty.
     * Tag joins (T2.3) and folder globs (T2.4) are deferred to their own
     * tasks per the plan — they need a `whereExists` subquery and a
     * dialect-portable fnmatch translation respectively. `connectorTypes`
     * is accepted in the DTO + payload but applies no constraint until
     * a `connector_type` column is added (currently the value lives in
     * `metadata.connector` JSON which is brittle to query under SQLite).
     */
    private function applyFilters(Builder $q, RetrievalFilters $f): void
    {
        if ($f->projectKeys !== []) {
            // KnowledgeChunk has its own `project_key` column denormalised
            // from KnowledgeDocument (DocumentIngestor::persistChunks
            // copies it on insert) so the chunk-level whereIn uses the
            // index directly without joining knowledge_documents — same
            // legacy filter shape, just with multiple values. The
            // implicit FK consistency between chunk.project_key and
            // document.project_key is enforced at write time, so a
            // separate document-level whereHas would be redundant.
            $q->whereIn('knowledge_chunks.project_key', $f->projectKeys);
        }

        $hasDocumentLevelFilters = $f->sourceTypes !== []
            || $f->canonicalTypes !== []
            || $f->docIds !== []
            || $f->languages !== []
            || $f->dateFrom !== null
            || $f->dateTo !== null;

        if ($hasDocumentLevelFilters) {
            $q->whereHas('document', function ($docQuery) use ($f): void {
                if ($f->sourceTypes !== []) {
                    $docQuery->whereIn('source_type', $f->sourceTypes);
                }
                if ($f->canonicalTypes !== []) {
                    $docQuery->whereIn('canonical_type', $f->canonicalTypes);
                }
                if ($f->docIds !== []) {
                    $docQuery->whereIn('id', $f->docIds);
                }
                if ($f->languages !== []) {
                    $docQuery->whereIn('language', $f->languages);
                }
                if ($f->dateFrom !== null) {
                    $docQuery->where('indexed_at', '>=', $f->dateFrom);
                }
                if ($f->dateTo !== null) {
                    $docQuery->where('indexed_at', '<=', $f->dateTo);
                }
            });
        }

        if ($f->tagSlugs !== []) {
            // Tag matching is exact-on-slug via a whereExists subquery
            // joining `knowledge_document_tags` (the pivot) with `kb_tags`
            // (where the slug lives). Slugs are user-facing identifiers —
            // exact match avoids the R19 LIKE-escape concern entirely
            // (no `%` / `_` / `\` to worry about because we never use LIKE).
            //
            // Project boundary: `knowledge_document_tags` only has FKs on
            // `knowledge_document_id` and `kb_tag_id` — the schema does
            // NOT prevent associating a tag from project A with a document
            // from project B (write-time application invariant, not
            // structural). To make the search query tenant-safe regardless,
            // explicitly constrain `kt.project_key = knowledge_chunks
            // .project_key` so the same slug across projects only matches
            // tags belonging to the current chunk's project.
            $q->whereExists(function ($sub) use ($f): void {
                $sub->select(DB::raw(1))
                    ->from('knowledge_document_tags as kdt')
                    ->join('kb_tags as kt', 'kt.id', '=', 'kdt.kb_tag_id')
                    ->whereColumn('kdt.knowledge_document_id', 'knowledge_chunks.knowledge_document_id')
                    ->whereColumn('kt.project_key', 'knowledge_chunks.project_key')
                    ->whereIn('kt.slug', $f->tagSlugs);
            });
        }

        // Folder globs handled in T2.4.
        // connectorTypes deferred until a `connector_type` column is added.
    }
}
