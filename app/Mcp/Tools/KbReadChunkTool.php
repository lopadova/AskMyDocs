<?php

namespace App\Mcp\Tools;

use App\Models\KnowledgeChunk;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read a specific knowledge chunk by ID.')]
#[IsReadOnly]
#[IsIdempotent]
class KbReadChunkTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'chunk_id' => $schema->integer()
                ->description('Numeric ID of the knowledge chunk.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        // R30 — scope to the MCP-resolved tenant (forTenant) so a client
        // bound to tenant A cannot read tenant B's chunk_text by enumerating
        // the global auto-increment id; KnowledgeChunk has no global read
        // scope, so the bare findOrFail here was completely unscoped. The
        // whereHas('document') additionally requires a non-trashed parent
        // document to exist (and applies AccessScopeScope only when a user is
        // authenticated — MCP runs token-only, so it is the forTenant scope,
        // not an ACL check, that enforces the tenant boundary here).
        $chunk = KnowledgeChunk::query()
            ->forTenant(app(TenantContext::class)->current())
            ->whereHas('document')
            ->with('document')
            ->findOrFail((int) $request->get('chunk_id'));

        return Response::json([
            'chunk' => [
                'id' => $chunk->id,
                'project_key' => $chunk->project_key,
                'heading_path' => $chunk->heading_path,
                'chunk_text' => $chunk->chunk_text,
                'metadata' => $chunk->metadata,
                'document' => [
                    'id' => $chunk->document?->id,
                    'title' => $chunk->document?->title,
                    'source_path' => $chunk->document?->source_path,
                    'source_type' => $chunk->document?->source_type,
                ],
            ],
        ]);
    }
}
