<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\AutoWiki\WikiIndexBuilder;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P4 — MCP surface (R44) of Auto-Wiki index building: rebuild the
 * per-project roll-up(s) + the per-tenant hub. Delegates to
 * {@see WikiIndexBuilder}, scoped to the active tenant (R30).
 */
#[Description('Rebuild the Auto-Wiki indices: per-project roll-ups + the per-tenant hub (the navigation map). Pass project_key to rebuild one project, omit to rebuild all projects in the tenant.')]
class KbBuildWikiIndexTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()->description('Rebuild only this project; omit to rebuild all projects in the tenant.'),
        ];
    }

    public function handle(Request $request, WikiIndexBuilder $builder): Response
    {
        $projectKey = trim((string) ($request->get('project_key') ?? ''));

        $result = $builder->rebuild(
            app(TenantContext::class)->current(),
            $projectKey !== '' ? $projectKey : null,
        );

        return Response::json($result);
    }
}
