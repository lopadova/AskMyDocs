<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Fetch a canonical document by its project-scoped slug, including frontmatter and all chunks.')]
#[IsReadOnly]
#[IsIdempotent]
class KbDocumentBySlugTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()
                ->description('Project-scoped slug (e.g. "dec-cache-v2").')
                ->required(),
            'project_key' => $schema->string()
                ->description('Project scope — REQUIRED. Slugs are unique per project, not globally.')
                ->required(),
            'include_chunks' => $schema->boolean()
                ->description('Include the document\'s chunks in the response (default: true).')
                ->default(true),
        ];
    }

    public function handle(Request $request): Response
    {
        $slug = (string) $request->get('slug');
        $projectKey = (string) $request->get('project_key');
        if ($slug === '' || $projectKey === '') {
            return Response::json(['error' => 'slug and project_key are required']);
        }

        $includeChunks = (bool) ($request->get('include_chunks') ?? true);

        $doc = KnowledgeDocument::query()
            ->where('project_key', $projectKey)
            ->where('slug', $slug)
            ->where('is_canonical', true)
            ->first();

        if ($doc === null) {
            return Response::json(['error' => 'not_found', 'slug' => $slug, 'project_key' => $projectKey]);
        }

        $payload = [
            'document' => $this->formatDocument($doc),
        ];
        if ($includeChunks) {
            $payload['chunks'] = $this->loadChunks($doc);
        }

        return Response::json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDocument(KnowledgeDocument $doc): array
    {
        return [
            'id' => $doc->id,
            'doc_id' => $doc->doc_id,
            'slug' => $doc->slug,
            'project_key' => $doc->project_key,
            'title' => $doc->title,
            'source_path' => $doc->source_path,
            'canonical_type' => $doc->canonical_type,
            'canonical_status' => $doc->canonical_status,
            'retrieval_priority' => $doc->retrieval_priority,
            'indexed_at' => $doc->indexed_at?->toIso8601String(),
            'updated_at' => $doc->updated_at?->toIso8601String(),
            'frontmatter' => $doc->frontmatter_json,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadChunks(KnowledgeDocument $doc): array
    {
        return $doc->chunks()
            ->orderBy('chunk_order')
            ->get()
            ->map(fn ($chunk) => [
                'chunk_id' => $chunk->id,
                'order' => $chunk->chunk_order,
                'heading_path' => $chunk->heading_path,
                'text' => $chunk->chunk_text,
                'metadata' => $chunk->metadata,
            ])
            ->values()
            ->all();
    }
}
