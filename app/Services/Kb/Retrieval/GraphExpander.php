<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use App\Models\KbEdge;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Support\Collection;

/**
 * Graph-aware retrieval expansion (1-hop by default).
 *
 * Given a set of seed chunks returned by the base retrieval pipeline
 * (vector + FTS + Reranker), walk `kb_edges` from the canonical seed
 * documents to their neighbours (filtered by edge type) and return the
 * neighbour documents' best chunk — merged into the same array shape as
 * the primary results.
 *
 * Hard invariants:
 *   - Fully tenant-scoped: a seed from project X never expands into
 *     project Y. Project key MUST be passed; a null/empty project key
 *     short-circuits the expansion (graph is always multi-tenant).
 *   - Only canonical + retrievable targets are emitted (dangling nodes,
 *     superseded/deprecated/archived targets are filtered out).
 *   - Respects the allowlist in `config('kb.graph.expansion_edge_types')`.
 *   - Cap on total edges walked: `config('kb.graph.expansion_max_nodes')`.
 *   - No-op when the feature is disabled or when the seeds have no
 *     canonical slugs (nothing to expand from).
 *
 * The emitted chunks carry `metadata.origin = 'graph_expansion'` and
 * `metadata.edge_type` so the prompt composer can label them.
 */
class GraphExpander
{
    /**
     * @param  Collection<int, array{document: array{is_canonical: bool, slug: ?string, ...}, ...}>  $seedChunks
     * @return Collection<int, array{chunk_id: int, project_key: string, heading_path: ?string, chunk_text: string, metadata: array, vector_score: float, document: array}>
     */
    public function expand(Collection $seedChunks, ?string $projectKey): Collection
    {
        if (! (bool) config('kb.graph.expansion_enabled', true)) {
            return collect();
        }
        if ($projectKey === null || $projectKey === '') {
            return collect();
        }

        $seedSlugs = $this->extractSeedSlugs($seedChunks);
        if ($seedSlugs === []) {
            return collect();
        }

        $allowedEdgeTypes = $this->allowedEdgeTypes();
        if ($allowedEdgeTypes === []) {
            return collect();
        }

        $edges = $this->loadNeighbourEdges($projectKey, $seedSlugs, $allowedEdgeTypes);
        if ($edges->isEmpty()) {
            return collect();
        }

        return $this->resolveEdgesToChunks($edges, $projectKey, $seedSlugs);
    }

    // -----------------------------------------------------------------
    // seed extraction
    // -----------------------------------------------------------------

    /**
     * @param  Collection<int, array>  $seedChunks
     * @return list<string>
     */
    private function extractSeedSlugs(Collection $seedChunks): array
    {
        $seen = [];
        $slugs = [];
        foreach ($seedChunks as $chunk) {
            $document = $chunk['document'] ?? [];
            if (! (bool) ($document['is_canonical'] ?? false)) {
                continue;
            }
            $slug = $document['slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                continue;
            }
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $slugs[] = $slug;
        }
        return $slugs;
    }

    // -----------------------------------------------------------------
    // config access
    // -----------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function allowedEdgeTypes(): array
    {
        $raw = config('kb.graph.expansion_edge_types', [
            'depends_on',
            'implements',
            'decision_for',
            'related_to',
            'supersedes',
        ]);
        if (! is_array($raw)) {
            return [];
        }
        return array_values(array_filter($raw, static fn ($v) => is_string($v) && $v !== ''));
    }

    private function maxNodes(): int
    {
        $v = (int) config('kb.graph.expansion_max_nodes', 20);
        return $v > 0 ? $v : 20;
    }

    // -----------------------------------------------------------------
    // edge query
    // -----------------------------------------------------------------

    /**
     * @param  list<string>  $seedSlugs
     * @param  list<string>  $allowedEdgeTypes
     * @return Collection<int, KbEdge>
     */
    private function loadNeighbourEdges(string $projectKey, array $seedSlugs, array $allowedEdgeTypes): Collection
    {
        return KbEdge::query()
            ->where('project_key', $projectKey)
            ->whereIn('from_node_uid', $seedSlugs)
            ->whereIn('edge_type', $allowedEdgeTypes)
            ->orderByDesc('weight')
            ->orderBy('id')
            ->limit($this->maxNodes())
            ->get();
    }

    // -----------------------------------------------------------------
    // edge → chunk resolution
    // -----------------------------------------------------------------

    /**
     * @param  Collection<int, KbEdge>  $edges
     * @param  list<string>  $seedSlugs
     * @return Collection<int, array>
     */
    private function resolveEdgesToChunks(Collection $edges, string $projectKey, array $seedSlugs): Collection
    {
        $seedSet = array_flip($seedSlugs);
        $targetSlugs = $this->collectTargetSlugs($edges, $seedSet);
        if ($targetSlugs === []) {
            return collect();
        }

        $targetDocs = $this->loadRetrievableTargets($projectKey, $targetSlugs);
        if ($targetDocs->isEmpty()) {
            return collect();
        }

        return $this->buildExpandedChunks($edges, $targetDocs);
    }

    /**
     * @param  Collection<int, KbEdge>  $edges
     * @param  array<string, int>  $seedSet
     * @return list<string>
     */
    private function collectTargetSlugs(Collection $edges, array $seedSet): array
    {
        $seen = [];
        $out = [];
        foreach ($edges as $edge) {
            $slug = $edge->to_node_uid;
            if (isset($seedSet[$slug])) {
                continue;
            }
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $slug;
        }
        return $out;
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<string, KnowledgeDocument>
     */
    private function loadRetrievableTargets(string $projectKey, array $slugs): Collection
    {
        return KnowledgeDocument::query()
            ->where('project_key', $projectKey)
            ->whereIn('slug', $slugs)
            ->where('is_canonical', true)
            ->whereIn('canonical_status', ['accepted', 'review'])
            ->where('status', '!=', 'archived')
            ->get()
            ->keyBy('slug');
    }

    /**
     * @param  Collection<int, KbEdge>  $edges
     * @param  Collection<string, KnowledgeDocument>  $targetDocsBySlug
     * @return Collection<int, array>
     */
    private function buildExpandedChunks(Collection $edges, Collection $targetDocsBySlug): Collection
    {
        $chunksByDocId = $this->batchFetchRepresentativeChunks($targetDocsBySlug->pluck('id')->all());

        $emitted = [];
        $out = [];
        foreach ($edges as $edge) {
            if (isset($emitted[$edge->to_node_uid])) {
                continue;
            }
            $targetDoc = $targetDocsBySlug->get($edge->to_node_uid);
            if ($targetDoc === null) {
                continue;
            }
            $chunk = $chunksByDocId[$targetDoc->id] ?? null;
            if ($chunk === null) {
                continue;
            }
            $emitted[$edge->to_node_uid] = true;
            $out[] = $this->formatExpandedChunk($chunk, $targetDoc, $edge);
        }
        return collect($out);
    }

    /**
     * Fetch the representative chunk (lowest chunk_order) for each target
     * document in a SINGLE query to avoid the N+1 pattern that one per-edge
     * `pickRepresentativeChunk()` call would produce. With
     * `kb.graph.expansion_max_nodes` at its default of 20 this saves up to
     * 19 queries per chat request.
     *
     * The query selects all chunks for the target docs, ordered by
     * (document_id, chunk_order). The PHP `groupBy(document_id)->first()`
     * then keeps only the lowest-ordered chunk per doc — deterministic and
     * index-friendly (the existing `idx_kb_chunks_doc_order` covers both
     * the filter and the sort).
     *
     * @param  list<int>  $docIds
     * @return array<int, KnowledgeChunk>  keyed by knowledge_document_id
     */
    private function batchFetchRepresentativeChunks(array $docIds): array
    {
        if ($docIds === []) {
            return [];
        }
        return KnowledgeChunk::query()
            ->whereIn('knowledge_document_id', $docIds)
            ->orderBy('knowledge_document_id')
            ->orderBy('chunk_order')
            ->get()
            ->groupBy('knowledge_document_id')
            ->map(static fn ($group) => $group->first())
            ->all();
    }

    /**
     * @return array{chunk_id: int, project_key: string, heading_path: ?string, chunk_text: string, metadata: array, vector_score: float, document: array}
     */
    private function formatExpandedChunk(KnowledgeChunk $chunk, KnowledgeDocument $doc, KbEdge $edge): array
    {
        $baseMetadata = is_array($chunk->metadata) ? $chunk->metadata : [];
        return [
            'chunk_id' => (int) $chunk->id,
            'project_key' => (string) $doc->project_key,
            'heading_path' => $chunk->heading_path,
            'chunk_text' => (string) $chunk->chunk_text,
            'metadata' => array_merge($baseMetadata, [
                'origin' => 'graph_expansion',
                'edge_type' => $edge->edge_type,
                'edge_weight' => (float) $edge->weight,
                'from_slug' => $edge->from_node_uid,
            ]),
            'vector_score' => 0.0,
            'document' => [
                'id' => (int) $doc->id,
                'title' => (string) $doc->title,
                'source_path' => (string) $doc->source_path,
                'source_type' => (string) $doc->source_type,
                'doc_id' => $doc->doc_id,
                'slug' => $doc->slug,
                'is_canonical' => (bool) $doc->is_canonical,
                'canonical_type' => $doc->canonical_type,
                'canonical_status' => $doc->canonical_status,
                'retrieval_priority' => (int) ($doc->retrieval_priority ?? 50),
            ],
        ];
    }
}
