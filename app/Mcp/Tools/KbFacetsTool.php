<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\Agent\KbFacetsService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.44/W4 (ADR 0035 §D6.1) — MCP surface of the corpus facets (R44): which
 * source types, canonical types/statuses, languages, tags and top-level
 * folders exist, with counts — so an agent builds sensible filters instead of
 * guessing. Thin adapter over {@see KbFacetsService}; counts are computed
 * under the MCP principal's own document ACL (R33) and the active tenant (R30).
 * `KB_MCP_FACETS_ENABLED` OFF → `{disabled: true}`.
 */
#[Description('List the filterable dimensions of the knowledge base with counts: source types, canonical types and statuses, languages, tags, top-level folders and the source-updated date range. Use it to build valid filters before searching. Read-only; scoped to the documents you may read.')]
#[IsReadOnly]
#[IsIdempotent]
class KbFacetsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Optional project key to restrict the facets to.')
                ->nullable(),
            'folder_depth' => $schema->integer()
                ->description('Folder prefix depth, 1 or 2 (default 2).')
                ->default(2),
        ];
    }

    public function handle(Request $request, KbFacetsService $kbFacetsService, TenantContext $tenants): Response
    {
        if (! (bool) config('kb.facets.enabled', false)) {
            return Response::json(['disabled' => true, 'flag' => 'KB_MCP_FACETS_ENABLED']);
        }

        $project = $request->get('project_key');

        return Response::json($kbFacetsService->for(
            $tenants->current(),
            is_string($project) && $project !== '' ? $project : null,
            max(1, min(2, (int) ($request->get('folder_depth') ?? 2))),
        ));
    }
}
