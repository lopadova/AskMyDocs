<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use App\Models\KnowledgeChunk;
use App\Models\ProjectMembership;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * v8.0/W3.4 — Counterfactual retrieval ("if I had filtered by Y you
 * would have cited Z"). Runs a mini-retrieval against up to 3 OTHER
 * projects the user has membership in, so the chat UI can surface
 * "here is what is in your other projects that loosely matched this
 * query" — a transparency feature, never fed to the LLM, gated by
 * a user preference toggle (default ON, sticky — W3.5).
 *
 * Critical RBAC contract (ADR 0014 §C.3): the candidate project set
 * is derived STRICTLY from `project_memberships` rows scoped to the
 * (tenant_id, user_id) pair — a project the user has no membership
 * in MUST NEVER appear in the response. The feature test
 * `CounterfactualServiceTest::test_rbac_strict_only_user_membership_projects_surface`
 * pins this invariant.
 *
 * Cost shape: 1 cache lookup + 1 mini-retrieval per neighbor project
 * (up to 3); cache TTL is 1h keyed on
 * `(query_hash, project_key, tenant_id, user_id)`;
 * mini-retrieval is semantic-only (no reranker, no graph expand, no
 * rejected injection) and capped at 5 chunks per project.
 */
final class CounterfactualService
{
    public function __construct(
        private readonly EmbeddingCacheService $embeddingCache,
    ) {
    }

    /**
     * Pick up to 3 neighbor-project mini-retrievals for the calling
     * user. Returns an empty array when the user has no other
     * projects beyond the primary, when the calling controller
     * passed a null user (anonymous chat), or when the
     * `kb.counterfactual.enabled` config is false.
     *
     * @return array<int, array{project_key: string, top_chunks: array<int, array<string, mixed>>}>
     */
    public function pick(
        string $query,
        ?int $userId,
        string $tenantId,
        ?string $primaryProjectKey,
    ): array {
        if ($userId === null) {
            return [];
        }
        if (! (bool) config('kb.counterfactual.enabled', true)) {
            return [];
        }

        $neighborProjects = $this->resolveNeighborProjects(
            $userId,
            $tenantId,
            $primaryProjectKey,
        );

        if ($neighborProjects === []) {
            return [];
        }

        $maxNeighbors = (int) config('kb.counterfactual.max_neighbors', 3);
        $perProjectLimit = (int) config('kb.counterfactual.per_project_limit', 5);
        $minSimilarity = (float) config('kb.counterfactual.min_similarity', 0.25);
        $cacheTtl = (int) config('kb.counterfactual.cache_ttl_seconds', 3600);

        $out = [];
        foreach (array_slice($neighborProjects, 0, $maxNeighbors) as $projectKey) {
            $cacheKey = $this->cacheKey($query, $projectKey, $tenantId, $userId);
            $top = Cache::remember(
                $cacheKey,
                $cacheTtl,
                fn () => $this->miniRetrieval($query, $projectKey, $tenantId, $perProjectLimit, $minSimilarity)->all(),
            );

            if ($top === []) {
                continue;
            }

            $out[] = [
                'project_key' => $projectKey,
                'top_chunks' => $top,
            ];
        }

        return $out;
    }

    /**
     * Read the user's `project_memberships`, strictly scoped by
     * tenant_id (R30). Returns the keys ordered most-recent first so
     * a user who has churn-of-memberships sees the latest projects
     * first; the primary project (if any) is excluded.
     *
     * @return array<int, string>
     */
    private function resolveNeighborProjects(
        int $userId,
        string $tenantId,
        ?string $primaryProjectKey,
    ): array {
        $maxNeighbors = max(1, (int) config('kb.counterfactual.max_neighbors', 3));

        $rows = ProjectMembership::query()
            ->forTenant($tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($maxNeighbors + 1)
            ->pluck('project_key')
            ->all();

        if ($primaryProjectKey !== null) {
            $rows = array_values(array_filter(
                $rows,
                fn (string $key): bool => $key !== $primaryProjectKey,
            ));
        }

        return array_values(array_unique($rows));
    }

    /**
     * Build a deterministic cache key. SHA-256 keeps it short + safe
     * for any cache backend (Redis key length, file-cache path).
     */
    private function cacheKey(string $query, string $projectKey, string $tenantId, int $userId): string
    {
        return 'cf:'.hash('sha256', $tenantId.'|'.$userId.'|'.$projectKey.'|'.$query);
    }

    /**
     * Semantic-only mini-retrieval. Deliberately bypasses the full
     * `KbSearchService::search()` pipeline: no reranker, no graph
     * expansion, no rejected injection. The output is a list of
     * trimmed chunk previews suitable for the counterfactual panel,
     * NOT for feeding the LLM.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function miniRetrieval(
        string $query,
        string $projectKey,
        string $tenantId,
        int $limit,
        float $minSimilarity,
    ): Collection {
        $embeddingsResponse = $this->embeddingCache->generate([$query]);
        $queryEmbedding = $embeddingsResponse->embeddings[0];
        $vectorString = '['.implode(',', $queryEmbedding).']';

        // R30: `KnowledgeChunk` is `BelongsToTenant` with no global
        // scope, and `project_key` is NOT globally unique (two tenants
        // can legitimately share `default` / `engineering` / etc.).
        // Without `->forTenant(...)` a chunk from another tenant that
        // shares the same project_key would surface in the
        // counterfactual panel — RBAC catastrophe (Copilot iter-1 fix).
        $chunks = KnowledgeChunk::query()
            ->forTenant($tenantId)
            ->with('document')
            ->selectRaw('knowledge_chunks.*, (1 - (embedding <=> ?::vector)) as vector_score', [$vectorString])
            ->whereRaw('(1 - (embedding <=> ?::vector)) >= ?', [$vectorString, $minSimilarity])
            ->whereHas('document', fn ($q) => $q->where('status', '!=', 'archived'))
            ->where('knowledge_chunks.project_key', $projectKey)
            ->orderByDesc('vector_score')
            ->limit($limit)
            ->get();

        return $chunks->map(fn ($chunk): array => [
            'chunk_id' => $chunk->id,
            'project_key' => $chunk->project_key,
            'heading_path' => $chunk->heading_path,
            'chunk_text' => mb_substr((string) $chunk->chunk_text, 0, 400),
            'vector_score' => (float) ($chunk->vector_score ?? 0),
            'document' => [
                'id' => $chunk->document?->id,
                'title' => $chunk->document?->title,
                'source_path' => $chunk->document?->source_path,
                'source_type' => $chunk->document?->source_type,
                'doc_id' => $chunk->document?->doc_id,
                'slug' => $chunk->document?->slug,
                'is_canonical' => (bool) ($chunk->document?->is_canonical ?? false),
                'canonical_type' => $chunk->document?->canonical_type,
                'canonical_status' => $chunk->document?->canonical_status,
            ],
        ]);
    }
}
