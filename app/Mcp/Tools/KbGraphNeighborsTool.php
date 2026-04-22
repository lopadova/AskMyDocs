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

        $limit = min((int) ($request->get('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $edgeTypes = $this->normalizeEdgeTypes($request->get('edge_types'));

        $query = KbEdge::query()
            ->where('project_key', $projectKey)
            ->where('from_node_uid', $nodeUid);
        if ($edgeTypes !== []) {
            $query->whereIn('edge_type', $edgeTypes);
        }

        $edges = $query->orderByDesc('weight')->limit($limit)->get();
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
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeEdgeTypes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $type) {
            if (is_string($type) && $type !== '') {
                $out[] = $type;
            }
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
