<?php

namespace App\Mcp\Tools;

use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\ResultScoreFloor;
use App\Services\Kb\Retrieval\RetrievalFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Search the canonical knowledge base and return the most relevant chunks with source metadata.')]
#[IsReadOnly]
#[IsIdempotent]
class KbSearchTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural-language search query for the knowledge base.')
                ->required(),
            'project_key' => $schema->string()
                ->description('Optional project key filter.')
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of chunks to return.')
                ->default(6),
            'intent' => $schema->string()
                ->description('Optional disambiguation hint, e.g. "web page load times" for the query "performance". It steers re-ranking; it is not searched on its own.')
                ->nullable(),
            'min_score' => $schema->number()
                ->description('Optional. Drop results whose final score (cross-encoder blend when that stage ran, else rerank score, else vector score) is below this value.')
                ->nullable(),
        ];
    }

    public function handle(Request $request, KbSearchService $search): Response
    {
        $results = $search->search(
            query: (string) $request->get('query'),
            projectKey: $request->get('project_key'),
            limit: (int) ($request->get('limit') ?? 6),
            // v8.44/W4 — optional intent (additive). Without it `filters` stays null:
            // the legacy, byte-identical call.
            filters: is_string($intent = $request->get('intent')) && trim($intent) !== ''
                ? RetrievalFilters::forLegacyProject($request->get('project_key'))->withIntent($intent)
                : null,
        );

        $minScore = $request->get('min_score');

        return Response::json([
            'results' => ResultScoreFloor::apply($results->values(), is_numeric($minScore) ? (float) $minScore : null)->all(),
        ]);
    }
}
