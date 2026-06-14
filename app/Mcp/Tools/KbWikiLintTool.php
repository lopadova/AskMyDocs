<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\AutoWiki\WikiLinter;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P5 — MCP surface (R44) of Auto-Wiki lint: report wiki-health issues
 * (dangling / orphan / stale cross-refs / missing index) for a project, and
 * optionally apply the safe auto-fix. Delegates to {@see WikiLinter}, scoped to
 * the active tenant (R30).
 */
#[Description('Lint a project\'s Auto-Wiki for structural health: dangling targets, orphan pages, stale cross-references (to deprecated/superseded/deleted docs), and a missing index. Pass fix=true to also prune leftover dangling nodes.')]
class KbWikiLintTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()->required(),
            'fix' => $schema->boolean()->description('Also apply safe auto-fixes (prune leftover dangling nodes).'),
        ];
    }

    public function handle(Request $request, WikiLinter $linter): Response
    {
        $projectKey = trim((string) $request->get('project_key'));
        if ($projectKey === '') {
            return Response::json(['error' => 'project_key is required']);
        }

        $tenantId = app(TenantContext::class)->current();
        $report = $linter->lint($tenantId, $projectKey);

        if ($request->get('fix') === true) {
            $report['fixed'] = $linter->fix($tenantId, $projectKey);
        }

        return Response::json($report);
    }
}
