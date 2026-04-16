<?php

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List recently indexed or updated knowledge documents.')]
#[IsReadOnly]
#[IsIdempotent]
class KbRecentChangesTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Optional project key filter.')
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of documents to return.')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = KnowledgeDocument::query()->orderByDesc('indexed_at');

        if ($request->get('project_key')) {
            $query->where('project_key', $request->get('project_key'));
        }

        $documents = $query
            ->limit((int) ($request->get('limit') ?? 10))
            ->get([
                'id',
                'project_key',
                'title',
                'source_path',
                'source_type',
                'indexed_at',
                'updated_at',
            ]);

        return Response::json([
            'documents' => $documents,
        ]);
    }
}
