<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KbEdge;
use App\Models\KbNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Return the subgraph reachable from a seed slug up to N hops. Tenant-scoped. Capped at max_nodes.')]
#[IsReadOnly]
#[IsIdempotent]
class KbGraphSubgraphTool extends Tool
{
    private const DEFAULT_HOPS = 1;
    private const MAX_HOPS = 2;
    private const DEFAULT_MAX_NODES = 30;
    private const HARD_MAX_NODES = 200;

    public function schema(JsonSchema $schema): array
    {
        return [
            'seed_slug' => $schema->string()
                ->description('Slug of the seed canonical node (e.g. "dec-cache-v2").')
                ->required(),
            'project_key' => $schema->string()
                ->description('Project scope — REQUIRED. Graph is always tenant-scoped.')
                ->required(),
            'hops' => $schema->integer()
                ->description('How many hops to walk. 1 = immediate neighbours, 2 = include their neighbours. Max 2.')
                ->default(self::DEFAULT_HOPS),
            'max_nodes' => $schema->integer()
                ->description('Hard cap on the total number of nodes returned (default 30, max 200).')
                ->default(self::DEFAULT_MAX_NODES),
        ];
    }

    public function handle(Request $request): Response
    {
        $seed = (string) $request->get('seed_slug');
        $projectKey = (string) $request->get('project_key');
        if ($seed === '' || $projectKey === '') {
            return Response::json(['error' => 'seed_slug and project_key are required', 'nodes' => [], 'edges' => []]);
        }

        $hops = max(1, min((int) ($request->get('hops') ?? self::DEFAULT_HOPS), self::MAX_HOPS));
        $maxNodes = max(1, min((int) ($request->get('max_nodes') ?? self::DEFAULT_MAX_NODES), self::HARD_MAX_NODES));

        [$visitedNodes, $collectedEdges, $truncated] = $this->walkBfs($projectKey, $seed, $hops, $maxNodes);

        $nodeRows = KbNode::where('project_key', $projectKey)
            ->whereIn('node_uid', array_keys($visitedNodes))
            ->get()
            ->map(fn (KbNode $n) => [
                'node_uid' => $n->node_uid,
                'node_type' => $n->node_type,
                'label' => $n->label,
                'source_doc_id' => $n->source_doc_id,
                'dangling' => (bool) ($n->payload_json['dangling'] ?? false),
            ])
            ->values()
            ->all();

        return Response::json([
            'seed_slug' => $seed,
            'project_key' => $projectKey,
            'hops' => $hops,
            'nodes' => $nodeRows,
            'edges' => array_values($collectedEdges),
            'truncated' => $truncated,
        ]);
    }

    /**
     * Breadth-first walk bounded by hops + max_nodes. Returns a third
     * element indicating whether the walk actually HIT the cap (i.e. at
     * least one unvisited neighbour was skipped). A reachable subgraph
     * that naturally contains exactly max_nodes is NOT truncation.
     *
     * @return array{0: array<string, int>, 1: array<string, array>, 2: bool}
     */
    private function walkBfs(string $projectKey, string $seed, int $hops, int $maxNodes): array
    {
        $visited = [$seed => 0];
        $edgesByUid = [];
        $frontier = [$seed];
        $truncated = false;

        for ($depth = 1; $depth <= $hops; $depth++) {
            if ($frontier === []) {
                break;
            }
            $edges = KbEdge::where('project_key', $projectKey)
                ->whereIn('from_node_uid', $frontier)
                ->orderByDesc('weight')
                ->limit($maxNodes * 5)
                ->get();

            $nextFrontier = [];
            foreach ($edges as $edge) {
                $edgesByUid[$edge->edge_uid] = [
                    'edge_uid' => $edge->edge_uid,
                    'from_node_uid' => $edge->from_node_uid,
                    'to_node_uid' => $edge->to_node_uid,
                    'edge_type' => $edge->edge_type,
                    'weight' => (float) $edge->weight,
                    'provenance' => $edge->provenance,
                ];
                if (isset($visited[$edge->to_node_uid])) {
                    continue;
                }
                if (count($visited) >= $maxNodes) {
                    // An actually-new neighbour we had to skip because the
                    // cap is full. This is real truncation.
                    $truncated = true;
                    break;
                }
                $visited[$edge->to_node_uid] = $depth;
                $nextFrontier[] = $edge->to_node_uid;
            }
            $frontier = $nextFrontier;
        }

        return [$visited, $edgesByUid, $truncated];
    }
}
