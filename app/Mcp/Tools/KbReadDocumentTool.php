<?php

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read a canonical knowledge document and return its metadata plus chunks.')]
#[IsReadOnly]
#[IsIdempotent]
class KbReadDocumentTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('Numeric ID of the knowledge document.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        // R30 — scope the lookup to the MCP-resolved tenant so a client
        // bound to tenant A cannot read tenant B's documents by enumerating
        // the global auto-increment id. Mirrors every sibling KB MCP tool
        // (e.g. KbDocumentBySlugTool); the bare findOrFail here was the one
        // read-by-id path that bypassed the tenant boundary.
        $document = KnowledgeDocument::query()
            ->forTenant(app(TenantContext::class)->current())
            ->with('chunks')
            ->findOrFail((int) $request->get('document_id'));

        return Response::json([
            'document' => [
                'id' => $document->id,
                'project_key' => $document->project_key,
                'title' => $document->title,
                'source_path' => $document->source_path,
                'source_type' => $document->source_type,
                'metadata' => $document->metadata,
                'chunks' => $document->chunks
                    ->sortBy('chunk_order')
                    ->map(fn ($chunk) => [
                        'chunk_id' => $chunk->id,
                        'chunk_order' => $chunk->chunk_order,
                        'heading_path' => $chunk->heading_path,
                        'chunk_text' => $chunk->chunk_text,
                    ])->values()->all(),
            ],
        ]);
    }
}
