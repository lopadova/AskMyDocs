<?php

namespace App\Mcp\Tools;

use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\RetrievalFilters;
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
            'intent' => $schema->string()
                ->description('Optional disambiguation hint, e.g. "web page load times" for the query "performance". It steers re-ranking; it is not searched on its own.')
                ->nullable(),
        ];
    }

    public function handle(Request $request, KbSearchService $search): Response
    {
        $results = $search->search(
            query: (string) $request->get('query'),
            projectKey: (string) $request->get('project_key'),
            limit: (int) ($request->get('limit') ?? 6),
            // v8.44/W4 — optional intent (additive). Without it `filters` stays null:
            // the legacy, byte-identical call.
            filters: is_string($intent = $request->get('intent')) && trim($intent) !== ''
                ? RetrievalFilters::forLegacyProject((string) $request->get('project_key'))->withIntent($intent)
                : null,
        );

        return Response::json([
            'results' => $results->values()->all(),
        ]);
    }
}
