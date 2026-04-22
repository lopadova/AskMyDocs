<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List 1-hop neighbours of a canonical node, filtered by edge type. Tenant-scoped by project_key.')]
#[IsReadOnly]
#[IsIdempotent]
class KbGraphNeighborsTool extends Tool
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_uid' => $schema->string()
                ->description('Slug of the canonical node to expand from (e.g. "dec-cache-v2").')
                ->required(),
            'project_key' => $schema->string()
                ->description('Project scope — REQUIRED. Graph is always tenant-scoped.')
                ->required(),
            'edge_types' => $schema->array()
                ->description('Optional filter: only edges of these types (decision_for, depends_on, related_to, implements, supersedes, invalidated_by, documented_by, affects, owned_by, uses). Empty = all allowed types from config.')
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Max neighbours to return (default 20, max 100).')
                ->default(self::DEFAULT_LIMIT),
        ];
    }

    public function handle(Request $request): Response
    {
        $nodeUid = (string) $request->get('node_uid');
        $projectKey = (string) $request->get('project_key');

        if ($projectKey === '' || $nodeUid === '') {
            return Response::json(['error' => 'node_uid and project_key are required', 'neighbours' => []]);
        }

        // Clamp on BOTH sides — a negative limit becomes `LIMIT -1` on
        // PostgreSQL which effectively disables the cap and would return
        // an unbounded result set. `max(1, ...)` blocks that.
        $rawLimit = (int) ($request->get('limit') ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($rawLimit, self::MAX_LIMIT));

        $edgeTypes = $this->resolveAllowedEdgeTypes($request->get('edge_types'));
        if ($edgeTypes === []) {
            // User or config allowlist ends up empty — return an empty result
            // rather than falling back to "every edge type in the DB".
            return Response::json(['neighbours' => [], 'count' => 0]);
        }

        $edges = KbEdge::query()
            ->where('project_key', $projectKey)
            ->where('from_node_uid', $nodeUid)
            ->whereIn('edge_type', $edgeTypes)
            ->orderByDesc('weight')
            ->limit($limit)
            ->get();
        if ($edges->isEmpty()) {
            return Response::json(['neighbours' => [], 'count' => 0]);
        }

        $targetSlugs = $edges->pluck('to_node_uid')->unique()->values()->all();
        $nodesBySlug = KbNode::where('project_key', $projectKey)
            ->whereIn('node_uid', $targetSlugs)
            ->get()
            ->keyBy('node_uid');
        $docsBySlug = KnowledgeDocument::where('project_key', $projectKey)
            ->whereIn('slug', $targetSlugs)
            ->where('is_canonical', true)
            ->get()
            ->keyBy('slug');

        $neighbours = $edges->map(fn (KbEdge $edge) => $this->formatNeighbour($edge, $nodesBySlug, $docsBySlug))->values()->all();

        return Response::json([
            'neighbours' => $neighbours,
            'count' => count($neighbours),
        ]);
    }

    /**
     * Resolve the effective edge-type allowlist.
     *
     * Always intersects with `kb.graph.expansion_edge_types` so the tool
     * cannot surface edges outside the operator-allowed set (the contract
     * promised in the schema description: "Empty = all allowed types from
     * config"). When the user passes a non-empty list, we keep only the
     * entries that are ALSO in the operator allowlist; an empty user input
     * falls back to the full operator allowlist.
     *
     * @param  mixed  $raw
     * @return list<string>
     */
    private function resolveAllowedEdgeTypes(mixed $raw): array
    {
        $operatorAllowlist = $this->operatorAllowlist();
        if ($operatorAllowlist === []) {
            return [];
        }
        $userRequested = $this->normalizeStringList($raw);
        if ($userRequested === []) {
            return $operatorAllowlist;
        }
        $intersection = array_values(array_intersect($userRequested, $operatorAllowlist));
        return $intersection;
    }

    /**
     * @return list<string>
     */
    private function operatorAllowlist(): array
    {
        $raw = config('kb.graph.expansion_edge_types', [
            'depends_on',
            'implements',
            'decision_for',
            'related_to',
            'supersedes',
        ]);
        return $this->normalizeStringList($raw);
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            if (isset($seen[$entry])) {
                continue;
            }
            $seen[$entry] = true;
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatNeighbour(KbEdge $edge, $nodesBySlug, $docsBySlug): array
    {
        $node = $nodesBySlug->get($edge->to_node_uid);
        $doc = $docsBySlug->get($edge->to_node_uid);
        return [
            'node_uid' => $edge->to_node_uid,
            'node_type' => $node?->node_type,
            'label' => $node?->label,
            'dangling' => (bool) ($node?->payload_json['dangling'] ?? false),
            'edge_type' => $edge->edge_type,
            'edge_weight' => (float) $edge->weight,
            'provenance' => $edge->provenance,
            'document' => $doc ? [
                'id' => $doc->id,
                'doc_id' => $doc->doc_id,
                'title' => $doc->title,
                'source_path' => $doc->source_path,
                'canonical_type' => $doc->canonical_type,
                'canonical_status' => $doc->canonical_status,
            ] : null,
        ];
    }
}
