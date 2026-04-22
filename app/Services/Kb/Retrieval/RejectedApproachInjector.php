<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Anti-repetition memory: surface rejected-approach documents that
 * correlate with the current query, so the LLM prompt composer can
 * inject them under a `⚠ REJECTED APPROACHES` block and the model
 * stops re-proposing already-dismissed options.
 *
 * Contract:
 *   - Always tenant-scoped: a null/empty projectKey short-circuits.
 *   - Only canonical + `accepted` rejected-approach docs are considered.
 *   - One chunk per rejected doc (the document-level similarity is the
 *     best chunk similarity).
 *   - Similarity is computed against the query embedding (obtained via
 *     the shared EmbeddingCacheService so cache hits are reused).
 *   - Docs under `kb.rejected.min_similarity` are dropped.
 *   - Returns at most `maxDocs` (caller override) or
 *     `kb.rejected.injection_max_docs` chunks.
 */
class RejectedApproachInjector
{
    public function __construct(
        private readonly EmbeddingCacheService $embeddings,
        private readonly CosineCalculator $cosine = new CosineCalculator(),
    ) {
    }

    /**
     * @return Collection<int, array{chunk_id: int, project_key: string, heading_path: ?string, chunk_text: string, metadata: array, vector_score: float, document: array}>
     */
    public function pick(string $query, ?string $projectKey, ?int $maxDocs = null): Collection
    {
        if (! (bool) config('kb.rejected.injection_enabled', true)) {
            return collect();
        }
        if ($projectKey === null || $projectKey === '') {
            return collect();
        }

        $cap = $this->resolveCap($maxDocs);
        $threshold = (float) config('kb.rejected.min_similarity', 0.45);

        $candidates = $this->loadCandidateChunks($projectKey);
        if ($candidates->isEmpty()) {
            return collect();
        }

        $queryEmbedding = $this->embedQuery($query);
        $ranked = $this->rankByBestChunkSimilarity($candidates, $queryEmbedding, $threshold);

        return $ranked->take($cap);
    }

    // -----------------------------------------------------------------
    // configuration helpers
    // -----------------------------------------------------------------

    private function resolveCap(?int $override): int
    {
        if ($override !== null && $override > 0) {
            return $override;
        }
        $configured = (int) config('kb.rejected.injection_max_docs', 3);
        return $configured > 0 ? $configured : 3;
    }

    // -----------------------------------------------------------------
    // data loading
    // -----------------------------------------------------------------

    /**
     * Load candidate chunks for rejected-approach injection.
     *
     * Performance-critical: runs on every chat request. To keep it bounded:
     *   - `chunk_order = 0` restricts to the **summary chunk** of each
     *     rejected-approach document. The summary/reason in a rejected-
     *     approach doc is conventionally at the head, and rejected docs
     *     are usually short — loading all chunks would be wasteful and
     *     unbounded as the tenant grows.
     *   - `project_key` is filtered on `knowledge_chunks` directly (not
     *     just via `whereHas`) so the chunks-table index on `project_key`
     *     is used and the full index scan is avoided.
     *
     * @return Collection<int, KnowledgeChunk>
     */
    private function loadCandidateChunks(string $projectKey): Collection
    {
        return KnowledgeChunk::query()
            ->with('document')
            ->where('project_key', $projectKey)
            ->where('chunk_order', 0)
            ->whereHas('document', function ($query) use ($projectKey) {
                $query->where('project_key', $projectKey)
                    ->where('is_canonical', true)
                    ->where('canonical_type', 'rejected-approach')
                    ->where('canonical_status', 'accepted')
                    ->where('status', '!=', 'archived');
            })
            ->get();
    }

    /**
     * @return list<float>
     */
    private function embedQuery(string $query): array
    {
        $response = $this->embeddings->generate([$query]);
        return $response->embeddings[0] ?? [];
    }

    // -----------------------------------------------------------------
    // ranking
    // -----------------------------------------------------------------

    /**
     * Compute similarity for each candidate, filter by threshold, sort by
     * similarity desc. `loadCandidateChunks()` already returns ONE chunk
     * per rejected-approach doc (the chunk_order=0 summary), so no
     * per-doc deduplication is needed here.
     *
     * @param  Collection<int, KnowledgeChunk>  $candidates
     * @param  list<float>  $queryEmbedding
     * @return Collection<int, array>
     */
    private function rankByBestChunkSimilarity(
        Collection $candidates,
        array $queryEmbedding,
        float $threshold,
    ): Collection {
        $qualified = [];
        foreach ($candidates as $chunk) {
            $similarity = $this->safeSimilarity($queryEmbedding, $chunk);
            if ($similarity < $threshold) {
                continue;
            }
            $qualified[] = ['chunk' => $chunk, 'similarity' => $similarity];
        }

        usort($qualified, static fn ($a, $b) => $b['similarity'] <=> $a['similarity']);

        return collect($qualified)->map(fn ($entry) => $this->formatChunk($entry['chunk'], $entry['similarity']));
    }

    /**
     * Compute similarity for a single chunk without letting a corrupted
     * embedding (wrong dimension, typically after a provider switch) crash
     * the whole pick() call. Logs the mismatch and returns 0 so the chunk
     * is simply excluded from the result set.
     *
     * @param  list<float>  $queryEmbedding
     */
    private function safeSimilarity(array $queryEmbedding, KnowledgeChunk $chunk): float
    {
        try {
            return $this->cosine->similarity($queryEmbedding, $this->chunkEmbedding($chunk));
        } catch (\InvalidArgumentException $e) {
            Log::warning('RejectedApproachInjector: skipping chunk with dimension mismatch', [
                'chunk_id' => $chunk->id,
                'document_id' => $chunk->knowledge_document_id,
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    /**
     * @return list<float>
     */
    private function chunkEmbedding(KnowledgeChunk $chunk): array
    {
        $raw = $chunk->embedding;
        if (is_array($raw)) {
            return array_values(array_map('floatval', $raw));
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }
        return array_values(array_map('floatval', $decoded));
    }

    // -----------------------------------------------------------------
    // result formatting
    // -----------------------------------------------------------------

    /**
     * @return array{chunk_id: int, project_key: string, heading_path: ?string, chunk_text: string, metadata: array, vector_score: float, document: array}
     */
    private function formatChunk(KnowledgeChunk $chunk, float $similarity): array
    {
        $doc = $chunk->document;
        $baseMetadata = is_array($chunk->metadata) ? $chunk->metadata : [];
        return [
            'chunk_id' => (int) $chunk->id,
            'project_key' => (string) $chunk->project_key,
            'heading_path' => $chunk->heading_path,
            'chunk_text' => (string) $chunk->chunk_text,
            'metadata' => array_merge($baseMetadata, [
                'origin' => 'rejected',
                'similarity' => $similarity,
            ]),
            'vector_score' => $similarity,
            'document' => [
                'id' => (int) ($doc?->id ?? 0),
                'title' => (string) ($doc?->title ?? ''),
                'source_path' => (string) ($doc?->source_path ?? ''),
                'source_type' => (string) ($doc?->source_type ?? ''),
                'doc_id' => $doc?->doc_id,
                'slug' => $doc?->slug,
                'is_canonical' => (bool) ($doc?->is_canonical ?? false),
                'canonical_type' => $doc?->canonical_type,
                'canonical_status' => $doc?->canonical_status,
                'retrieval_priority' => (int) ($doc?->retrieval_priority ?? 50),
                'rejected_summary' => $this->extractRejectedSummary($doc),
            ],
        ];
    }

    private function extractRejectedSummary(?KnowledgeDocument $doc): ?string
    {
        if ($doc === null) {
            return null;
        }
        $fm = $doc->frontmatter_json;
        if (! is_array($fm)) {
            return null;
        }
        $derived = $fm['_derived'] ?? null;
        if (! is_array($derived)) {
            return $fm['summary'] ?? null;
        }
        return $derived['summary'] ?? ($fm['summary'] ?? null);
    }
}
