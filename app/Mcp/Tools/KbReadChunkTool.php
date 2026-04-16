<?php

namespace App\Mcp\Tools;

use App\Models\KnowledgeChunk;
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
        $chunk = KnowledgeChunk::with('document')
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
