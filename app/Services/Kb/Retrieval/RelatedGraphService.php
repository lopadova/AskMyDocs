<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use App\Models\KbEdge;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * v8.8/W6 — chat-side related-graph: the 1-hop `kb_edges` neighbours of the
 * documents an answer cited, so the chat UI can show a "Related" panel.
 *
 * Reuses the canonical graph (`kb_edges` / `kb_nodes`) the retrieval-time
 * GraphExpander walks, but exposes it as a read API for the FE. Walks BOTH
 * directions (a cited doc's dependencies AND the docs that depend on it).
 * Tenant + project scoped (R30); config-gated by the same
 * `kb.graph.expansion_enabled` knob; a no-op (empty) when the project has no
 * canonical graph — so consumers without canonical docs see nothing extra.
 */
final class RelatedGraphService
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  list<string>  $slugs  cited canonical doc slugs (node_uids)
     * @return list<array{slug: string, title: ?string, edge_type: string, direction: string, weight: float}>
     */
    public function relatedTo(array $slugs, string $projectKey): array
    {
        if (! (bool) config('kb.graph.expansion_enabled', true)) {
            return [];
        }

        $seeds = array_values(array_filter(array_unique($slugs), static fn ($s): bool => is_string($s) && $s !== ''));
        if ($seeds === [] || $projectKey === '') {
            return [];
        }

        $tenantId = $this->tenants->current();
        $limit = max(1, (int) config('kb.graph.expansion_max_nodes', 20));

        $edges = KbEdge::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where(function ($q) use ($seeds): void {
                $q->whereIn('from_node_uid', $seeds)
                    ->orWhereIn('to_node_uid', $seeds);
            })
            ->orderByDesc('weight')
            ->orderBy('id')
            ->limit($limit * 2) // over-fetch; we drop self-edges + dedupe below
            ->get();

        $seedSet = array_flip($seeds);
        $neighbours = $this->collectNeighbours($edges, $seedSet, $limit);
        if ($neighbours === []) {
            return [];
        }

        $titles = $this->resolveTitles($tenantId, $projectKey, array_keys($neighbours));

        return array_map(
            static fn (array $n): array => [
                'slug' => $n['slug'],
                'title' => $titles[$n['slug']] ?? null,
                'edge_type' => $n['edge_type'],
                'direction' => $n['direction'],
                'weight' => $n['weight'],
            ],
            array_values($neighbours),
        );
    }

    /**
     * The neighbour end of each edge (the side NOT in the seed set), deduped,
     * capped at $limit. Direction is `outgoing` when the seed is the FROM side
     * (a dependency of the cited doc), `incoming` when the seed is the TO side
     * (a doc that depends on the cited one).
     *
     * @param  Collection<int, KbEdge>  $edges
     * @param  array<string, int>  $seedSet
     * @return array<string, array{slug: string, edge_type: string, direction: string, weight: float}>
     */
    private function collectNeighbours(Collection $edges, array $seedSet, int $limit): array
    {
        $out = [];
        foreach ($edges as $edge) {
            $fromSeed = isset($seedSet[$edge->from_node_uid]);
            $toSeed = isset($seedSet[$edge->to_node_uid]);

            // Need exactly one end in the seed set; skip seed↔seed and
            // unrelated edges the OR-query may have surfaced.
            if ($fromSeed === $toSeed) {
                continue;
            }

            $neighbour = $fromSeed ? $edge->to_node_uid : $edge->from_node_uid;
            if ($neighbour === '' || isset($out[$neighbour])) {
                continue;
            }

            $out[$neighbour] = [
                'slug' => $neighbour,
                'edge_type' => (string) $edge->edge_type,
                'direction' => $fromSeed ? 'outgoing' : 'incoming',
                'weight' => (float) $edge->weight,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, string>  slug => title
     */
    private function resolveTitles(string $tenantId, string $projectKey, array $slugs): array
    {
        return KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->whereIn('slug', $slugs)
            ->pluck('title', 'slug')
            ->all();
    }
}
