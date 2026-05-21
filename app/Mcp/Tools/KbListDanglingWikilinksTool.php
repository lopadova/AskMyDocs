<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KbNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List dangling wikilinks for a project (kb_nodes payload_json.dangling=true). Read-only.')]
#[IsReadOnly]
class KbListDanglingWikilinksTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()->required(),
            'limit' => $schema->integer()->default(100),
        ];
    }

    public function handle(Request $request): Response
    {
        $projectKey = trim((string) $request->get('project_key'));
        if ($projectKey === '') {
            return Response::json(['error' => 'project_key is required', 'items' => []]);
        }

        $limit = max(1, min((int) ($request->get('limit') ?? 100), 500));
        $rows = KbNode::query()
            ->forProject($projectKey)
            ->where('payload_json->dangling', true)
            ->limit($limit)
            ->get(['node_uid', 'node_type', 'label', 'source_doc_id'])
            ->map(fn (KbNode $n) => [
                'node_uid' => $n->node_uid,
                'node_type' => $n->node_type,
                'label' => $n->label,
                'source_doc_id' => $n->source_doc_id,
            ])
            ->values()
            ->all();

        return Response::json([
            'project_key' => $projectKey,
            'count' => count($rows),
            'items' => $rows,
        ]);
    }
}

