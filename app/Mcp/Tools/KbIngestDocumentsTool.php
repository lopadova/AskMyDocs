<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\McpDocumentImportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Submit up to ten Markdown, text, PDF or DOCX documents to the connected project. Use text content for Markdown/text and base64 encoding for PDF/DOCX. Returns a browser approval URL; indexing starts only after the user approves that exact import. Do not open or approve it yourself. Poll kb-import-status-tool until ready before searching. Max 5 MB per file, 10 MB total. Identical retries reuse the original import.')]
#[IsIdempotent]
final class KbIngestDocumentsTool extends Tool
{
    use \App\Mcp\Tools\Concerns\HandlesConnectedProjectErrors;

    public function schema(JsonSchema $schema): array
    {
        return ['documents' => $schema->array()->min(1)->max(10)->items($schema->object([
            'filename' => $schema->string()->required(),
            'content' => $schema->string()->required(),
            'encoding' => $schema->string()->enum(['text', 'base64']),
        ]))->required()];
    }

    public function handle(Request $request, McpDocumentImportService $imports): Response
    {
        return $this->connectedResponse(fn () => $imports->prepare($request->all()));
    }
}
