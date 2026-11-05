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

#[Description('Describe the company and the single project authorized for this connection.')]
#[IsReadOnly]
#[IsIdempotent]
final class KbConnectedProjectTool extends Tool
{
    use \App\Mcp\Tools\Concerns\HandlesConnectedProjectErrors;

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request, ConnectedProjectWorkspace $workspace): Response
    {
        return $this->connectedResponse(fn () => $workspace->info());
    }
}
