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

#[Description('Read an authorized document in the connected project, in bounded pages of chunks.')]
#[IsReadOnly]
#[IsIdempotent]
final class KbConnectedDocumentTool extends Tool
{
    use \App\Mcp\Tools\Concerns\HandlesConnectedProjectErrors;

    public function schema(JsonSchema $schema): array
    {
        return ['document_id' => $schema->integer()->required(), 'offset' => $schema->integer()->min(0), 'limit' => $schema->integer()->min(1)->max(10)];
    }

    public function handle(Request $request, ConnectedProjectWorkspace $workspace): Response
    {
        return $this->connectedResponse(fn () => $workspace->document($request->all()));
    }
}
