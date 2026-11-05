<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\ConnectedProjectWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Search documents in the connected project, returning evidence and source identifiers.')]
#[IsReadOnly]
#[IsIdempotent]
final class KbConnectedSearchTool extends Tool
{
    use \App\Mcp\Tools\Concerns\HandlesConnectedProjectErrors;

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->required(), 'limit' => $schema->integer()->min(1)->max(10)];
    }

    public function handle(Request $request, ConnectedProjectWorkspace $workspace): Response
    {
        return $this->connectedResponse(fn () => $workspace->search($request->all()));
    }
}
