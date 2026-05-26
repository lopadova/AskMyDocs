<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KbEdge;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Suggest supersession chain from a slug by following supersedes edges recursively. Read-only.')]
#[IsReadOnly]
class KbSuggestSupersessionChainTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()->required(),
            'slug' => $schema->string()->required(),
            'max_hops' => $schema->integer()->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $projectKey = trim((string) $request->get('project_key'));
        $slug = trim((string) $request->get('slug'));
        if ($projectKey === '' || $slug === '') {
            return Response::json(['error' => 'project_key and slug are required', 'chain' => []]);
        }

        $maxHops = max(1, min((int) ($request->get('max_hops') ?? 20), 100));
        $seen = [$slug => true];
        $chain = [$slug];
        $cursor = $slug;

        for ($i = 0; $i < $maxHops; $i++) {
            $next = KbEdge::query()
                ->forTenant(app(TenantContext::class)->current())
                ->forProject($projectKey)
                ->where('edge_type', 'supersedes')
                ->where('from_node_uid', $cursor)
                ->orderByDesc('weight')
                ->value('to_node_uid');

            if (! is_string($next) || $next === '' || isset($seen[$next])) {
                break;
            }
            $seen[$next] = true;
            $chain[] = $next;
            $cursor = $next;
        }

        return Response::json([
            'project_key' => $projectKey,
            'slug' => $slug,
            'count' => count($chain),
            'chain' => $chain,
        ]);
    }
}

