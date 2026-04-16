<?php

namespace App\Mcp\Tools;

use App\Services\Kb\KbSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Search the canonical knowledge base constrained to a specific project.')]
#[IsReadOnly]
#[IsIdempotent]
class KbSearchByProjectTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Project key to constrain the search.')
                ->required(),
            'query' => $schema->string()
                ->description('Natural-language search query.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of chunks to return.')
                ->default(6),
        ];
    }

    public function handle(Request $request, KbSearchService $search): Response
    {
        $results = $search->search(
            query: (string) $request->get('query'),
            projectKey: (string) $request->get('project_key'),
            limit: (int) ($request->get('limit') ?? 6),
        );

        return Response::json([
            'results' => $results->values()->all(),
        ]);
    }
}
