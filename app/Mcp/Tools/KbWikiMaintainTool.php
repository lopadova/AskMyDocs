<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\AutoWiki\WikiMaintainer;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P9 — MCP surface (R44) of scheduled wiki maintenance: run the sweep
 * (rebuild indices + lint + backfill un-enriched docs) on-demand for a project
 * or the whole tenant. Delegates to {@see WikiMaintainer}, scoped to the active
 * tenant (R30).
 */
#[Description('Run Auto-Wiki maintenance: rebuild the indices, lint wiki health, and backfill enrichment for un-enriched docs. Omit project_key to maintain every project in the tenant. fix=true also applies safe lint fixes.')]
class KbWikiMaintainTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()->description('Maintain only this project; omit for all projects in the tenant.'),
            'fix' => $schema->boolean()->description('Also apply safe lint auto-fixes.'),
            'backfill' => $schema->integer()->description('Max un-enriched docs to backfill per project (0-500).'),
        ];
    }

    public function handle(Request $request, WikiMaintainer $maintainer): Response
    {
        $projectKey = trim((string) ($request->get('project_key') ?? ''));
        $backfillRaw = $request->get('backfill');

        $result = $maintainer->maintain(
            app(TenantContext::class)->current(),
            $projectKey !== '' ? $projectKey : null,
            $request->get('fix') === true,
            is_numeric($backfillRaw) ? max(0, min(500, (int) $backfillRaw)) : null,
        );

        return Response::json($result);
    }
}
