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
 * v8.11/P4 — MCP surface (R44) reading the Auto-Wiki map: the per-tenant hub +
 * every per-project roll-up. This is the anchor an agentic client consults to
 * pick where to navigate (P6). Read-only; tenant-scoped (R30).
 */
#[Description('Read the Auto-Wiki navigation map for the tenant: the per-tenant hub (projects + page/concept counts) and each project roll-up. Use this to discover what exists before navigating the graph.')]
class KbWikiHubTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request, WikiIndexBuilder $builder): Response
    {
        return Response::json($builder->hub(app(TenantContext::class)->current()));
    }
}
