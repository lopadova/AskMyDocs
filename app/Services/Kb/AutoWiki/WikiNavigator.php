<?php

declare(strict_types=1);

namespace App\Services\Kb\AutoWiki;

use App\Models\KbEdge;
use App\Models\KbWikiIndex;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;

/**
 * v8.11/P6 — agentic graph-navigation retrieval (Karpathy "query" / AutoSci BFS
 * + anchor-driven discovery).
 *
 * A multi-hop, budget-bounded BFS over `kb_edges` — the "navigate the wiki"
 * primitive that goes beyond the 1-hop {@see \App\Services\Kb\Retrieval\GraphExpander}.
 * Two entry modes:
 *   - {@see navigate()} — BFS from explicit seed slugs;
 *   - {@see navigateFromAnchors()} — AutoSci anchor-driven discovery: first read
 *     the per-project index (P4 {@see KbWikiIndex}) to pick the most relevant
 *     anchors (recently-changed / central pages), then BFS from there — giving
 *     the walk a MAP instead of expanding blindly.
 *
 * Read-only + deterministic. This is the substrate consumed by the MCP
 * `KbWikiNavigateTool` (the primary agentic surface), the admin API, and the
 * CLI. Wiring it into the chat retrieval hot path + the benchmark-gated
 * default-ON flip is a deliberate follow-up — until then the chat path is
 * UNCHANGED (no regression risk; the navigator is purely additive).
 *
 * Tenant-scoped (R30): every edge/doc read is `forTenant`-scoped, so a BFS never
 * crosses into another tenant that happens to share a `project_key`.
 */
class WikiNavigator
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * BFS from explicit seed slugs up to `depth` hops, capped at `maxNodes`
     * total reached nodes. Returns the reached nodes with their hop distance and
     * the edge that reached them, plus the resolved doc title/type when known.
     *
     * @param  list<string>  $seedSlugs
     * @return array{seeds: list<string>, depth: int, max_nodes: int, reached: list<array{slug: string, hop: int, via_edge_type: ?string, from: ?string, title: ?string, type: ?string, exists: bool}>, truncated: bool}
     */
    public function navigate(string $tenantId, string $projectKey, array $seedSlugs, ?int $depth = null, ?int $maxNodes = null): array
    {
        $this->tenants->set($tenantId);

        $depth = $this->boundDepth($depth);
        $maxNodes = $this->boundMaxNodes($maxNodes);
        $allowedTypes = $this->allowedEdgeTypes();

        $seeds = $this->cleanSlugs($seedSlugs);
        $visited = array_fill_keys($seeds, true);
        $reached = [];
        $frontier = $seeds;
        $truncated = false;

        for ($hop = 1; $hop <= $depth && $frontier !== [] && ! $truncated; $hop++) {
            // Exclude already-visited targets in SQL so the per-hop weight-ordered
            // limit window is filled with genuinely-new candidates only — a node
            // with many high-weight back-edges to visited nodes can't starve a
            // reachable new (lower-weight) target out of the window.
            $edges = $this->edgesFrom($tenantId, $projectKey, $frontier, $allowedTypes, $maxNodes, array_keys($visited));
            $next = [];
            foreach ($edges as $edge) {
                $target = (string) $edge->to_node_uid;
                if ($target === '' || isset($visited[$target])) {
                    continue;
                }
                $visited[$target] = true;
                $reached[] = [
                    'slug' => $target,
                    'hop' => $hop,
                    'via_edge_type' => (string) $edge->edge_type,
                    'from' => (string) $edge->from_node_uid,
                ];
                $next[] = $target;
                if (count($reached) >= $maxNodes) {
                    $truncated = true;
                    break;
                }
            }
            $frontier = $next;
        }

        $reached = $this->resolveDocs($tenantId, $projectKey, $reached);

        return [
            'seeds' => $seeds,
            'depth' => $depth,
            'max_nodes' => $maxNodes,
            'reached' => $reached,
            'truncated' => $truncated,
        ];
    }

    /**
     * AutoSci anchor-driven discovery: pick anchors from the project index, then
     * BFS from them. Returns the navigate() result with the chosen anchors.
     *
     * @return array{seeds: list<string>, depth: int, max_nodes: int, reached: list<array<string,mixed>>, truncated: bool, anchors: list<string>}
     */
    public function navigateFromAnchors(string $tenantId, string $projectKey, ?int $depth = null, ?int $maxNodes = null): array
    {
        $anchors = $this->anchors($tenantId, $projectKey);
        $result = $this->navigate($tenantId, $projectKey, $anchors, $depth, $maxNodes);
        $result['anchors'] = $anchors;

        return $result;
    }

    /**
     * The anchor slugs for a project: the recently-changed / central pages
     * recorded in the per-project index roll-up (P4). Falls back to a direct
     * query of the project's domain-concept + canonical pages when no index row
     * exists yet.
     *
     * @return list<string>
     */
    public function anchors(string $tenantId, string $projectKey, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $row = KbWikiIndex::query()->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where('index_type', KbWikiIndex::TYPE_PROJECT)
            ->first();

        if ($row !== null && is_array($row->payload_json)) {
            $recent = $row->payload_json['recently_changed'] ?? [];
            $slugs = [];
            foreach (is_array($recent) ? $recent : [] as $page) {
                $slug = is_array($page) ? ($page['slug'] ?? null) : null;
                if (is_string($slug) && $slug !== '') {
                    $slugs[] = $slug;
                }
            }
            if ($slugs !== []) {
                return array_slice(array_values(array_unique($slugs)), 0, $limit);
            }
        }

        // Fallback: central pages straight from the corpus (concepts first).
        return KnowledgeDocument::query()->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->whereNotNull('slug')
            ->orderByRaw("CASE WHEN canonical_type = 'domain-concept' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->pluck('slug')
            ->all();
    }

    /**
     * @param  list<string>  $frontier
     * @param  list<string>  $allowedTypes
     * @param  list<string>  $visited      targets already reached/seeded — excluded so they don't consume the limit window
     * @return \Illuminate\Support\Collection<int, KbEdge>
     */
    private function edgesFrom(string $tenantId, string $projectKey, array $frontier, array $allowedTypes, int $limit, array $visited = [])
    {
        $q = KbEdge::query()->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->whereIn('from_node_uid', $frontier)
            ->orderByDesc('weight')
            ->orderBy('id')
            ->limit($limit);
        if ($allowedTypes !== []) {
            $q->whereIn('edge_type', $allowedTypes);
        }
        if ($visited !== []) {
            $q->whereNotIn('to_node_uid', $visited);
        }

        return $q->get(['id', 'from_node_uid', 'to_node_uid', 'edge_type', 'weight']);
    }

    /**
     * Resolve reached slugs to doc title/type + an `exists` flag (a slug with no
     * owning doc is a dangling target — still a valid navigation node).
     *
     * @param  list<array{slug: string, hop: int, via_edge_type: ?string, from: ?string}>  $reached
     * @return list<array{slug: string, hop: int, via_edge_type: ?string, from: ?string, title: ?string, type: ?string, exists: bool}>
     */
    private function resolveDocs(string $tenantId, string $projectKey, array $reached): array
    {
        $slugs = array_values(array_unique(array_map(static fn (array $r): string => $r['slug'], $reached)));
        if ($slugs === []) {
            return [];
        }

        $bySlug = [];
        foreach (array_chunk($slugs, 1000) as $chunk) {
            KnowledgeDocument::query()->forTenant($tenantId)
                ->where('project_key', $projectKey)
                ->whereIn('slug', $chunk)
                ->get(['slug', 'title', 'canonical_type'])
                ->each(function ($doc) use (&$bySlug): void {
                    $bySlug[(string) $doc->slug] = [
                        'title' => (string) ($doc->title ?? ''),
                        'type' => (string) ($doc->canonical_type ?? ''),
                    ];
                });
        }

        return array_map(static function (array $r) use ($bySlug): array {
            $hit = $bySlug[$r['slug']] ?? null;

            return [
                'slug' => $r['slug'],
                'hop' => $r['hop'],
                'via_edge_type' => $r['via_edge_type'],
                'from' => $r['from'],
                'title' => $hit['title'] ?? null,
                'type' => $hit['type'] ?? null,
                'exists' => $hit !== null,
            ];
        }, $reached);
    }

    /** @param list<string> $slugs @return list<string> */
    private function cleanSlugs(array $slugs): array
    {
        $out = [];
        foreach ($slugs as $s) {
            if (is_string($s) && trim($s) !== '' && ! in_array(trim($s), $out, true)) {
                $out[] = trim($s);
            }
        }

        return $out;
    }

    private function boundDepth(?int $depth): int
    {
        $default = (int) config('kb.graph.expansion_depth', 2);
        $depth ??= $default > 0 ? $default : 2;

        return max(1, min(5, $depth));
    }

    private function boundMaxNodes(?int $maxNodes): int
    {
        $default = (int) config('kb.graph.expansion_max_nodes', 20);
        $maxNodes ??= $default > 0 ? $default : 20;

        return max(1, min(200, $maxNodes));
    }

    /** @return list<string> */
    private function allowedEdgeTypes(): array
    {
        $raw = config('kb.graph.expansion_edge_types', ['depends_on', 'implements', 'decision_for', 'related_to', 'supersedes']);
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn ($v) => is_string($v) && $v !== ''));
    }
}
