<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\McpDocumentImportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Check a document import created by this connection. ready=true means every document was indexed; queued or awaiting_approval is not ready. Returns per-file status and document IDs. Poll with a short delay, never in a tight loop.')]
#[IsReadOnly]
final class KbImportStatusTool extends Tool
{
    use \App\Mcp\Tools\Concerns\HandlesConnectedProjectErrors;

    public function schema(JsonSchema $schema): array
    {
        return ['import_id' => $schema->string()->required()];
    }

    public function handle(Request $request, McpDocumentImportService $imports): Response
    {
        return $this->connectedResponse(fn () => $imports->status((string) $request->get('import_id')));
    }
}
