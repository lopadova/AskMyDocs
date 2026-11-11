<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\Knowledge\KnowledgeReadService;
use App\Services\Mcp\ConnectedProjectWorkspace;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Resolve knowledge entities by exact identifier or query, within one project and optional namespace. Only authorized original contributions are visible; identifiers preserve leading zeroes.')]
#[IsReadOnly]
#[IsIdempotent]
final class SearchKnowledgeEntitiesTool extends Tool
{
    protected string $name = 'search_knowledge_entities';

    public function schema(JsonSchema $schema): array
    {
        return ['project_key' => $schema->string(), 'query' => $schema->string(), 'namespace' => $schema->string(), 'limit' => $schema->integer()->min(1)->max(100)];
    }

    public function handle(Request $request, KnowledgeReadService $reader): Response
    {
        $project = request()->attributes->has('mcp.connection') ? app(ConnectedProjectWorkspace::class)->connection()->project_key : (string) $request->get('project_key');
        abort_if($project === '' || $project === '*' || ! auth()->user(), 403);
        return Response::json($reader->search(app(TenantContext::class)->current(), $project, (string) $request->get('query', ''), auth()->user(), $request->get('namespace'), (int) $request->get('limit', 20)));
    }
}
