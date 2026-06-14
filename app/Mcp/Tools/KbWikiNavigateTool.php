<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\AutoWiki\WikiNavigator;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P6 — MCP surface (R44), the PRIMARY agentic-navigation surface:
 * multi-hop BFS over the wiki graph from explicit seeds, or anchor-driven from
 * the project index when no seeds are given. Delegates to {@see WikiNavigator},
 * scoped to the active tenant (R30). Read-only.
 */
#[Description('Navigate the knowledge wiki graph multi-hop: BFS from seed slugs (or, with no seeds, from the project index anchors). Returns reached pages with hop distance, the edge type that reached each, and title/type. Use it to explore related knowledge beyond a single vector hit.')]
class KbWikiNavigateTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()->required(),
            'seeds' => $schema->array()->items($schema->string())->description('Seed slugs to BFS from; omit for anchor-driven discovery from the index.'),
            'depth' => $schema->integer()->description('BFS depth (1-5; default from config).'),
        ];
    }

    public function handle(Request $request, WikiNavigator $navigator): Response
    {
        $projectKey = trim((string) $request->get('project_key'));
        if ($projectKey === '') {
            return Response::json(['error' => 'project_key is required']);
        }

        $depthRaw = $request->get('depth');
        $depth = is_numeric($depthRaw) ? (int) $depthRaw : null;

        $seedsRaw = $request->get('seeds');
        $seeds = is_array($seedsRaw)
            ? array_values(array_filter($seedsRaw, static fn ($s) => is_string($s) && trim($s) !== ''))
            : [];

        $tenantId = app(TenantContext::class)->current();
        $result = $seeds === []
            ? $navigator->navigateFromAnchors($tenantId, $projectKey, $depth)
            : $navigator->navigate($tenantId, $projectKey, $seeds, $depth);

        return Response::json($result);
    }
}
