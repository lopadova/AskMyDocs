<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\Knowledge\KnowledgeReadService;
use App\Services\Kb\Knowledge\KnowledgeDocumentContext;
use App\Services\Mcp\ConnectedProjectWorkspace;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Read an entity timeline with original verified passages and a continuation cursor. Alternatively pass document_id and a section cursor to read distant chapters using section_map. Coverage is explicit and permission checks apply to every original.')]
#[IsReadOnly]
#[IsIdempotent]
final class ReadKnowledgeContextTool extends Tool
{
    protected string $name = 'read_knowledge_context';

    public function schema(JsonSchema $schema): array
    {
        return ['project_key' => $schema->string(), 'entity_id' => $schema->integer()->min(1), 'document_id' => $schema->integer()->min(1), 'cursor' => $schema->integer()->min(0), 'limit' => $schema->integer()->min(1)->max(100)];
    }

    public function handle(Request $request, KnowledgeReadService $reader, KnowledgeDocumentContext $documents): Response
    {
        $project = request()->attributes->has('mcp.connection') ? app(ConnectedProjectWorkspace::class)->connection()->project_key : (string) $request->get('project_key');
        abort_if($project === '' || $project === '*' || ! auth()->user(), 403);
        $tenant = app(TenantContext::class)->current();
        $result = $request->get('document_id') !== null
            ? $documents->read($tenant, $project, (int) $request->get('document_id'), auth()->user(), (int) $request->get('cursor', 0), (int) $request->get('limit', 1))
            : $reader->context($tenant, $project, (int) $request->get('entity_id'), auth()->user(), $request->get('cursor') === null ? null : (int) $request->get('cursor'), (int) $request->get('limit', 30));
        return Response::json($result ?? ['error' => 'knowledge_context_unavailable']);
    }
}
