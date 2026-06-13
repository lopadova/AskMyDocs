<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\AutoWiki\ConceptSynthesizer;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P3 — MCP surface (R44) of concept-page synthesis: an agentic client
 * sweeps a project and synthesizes auto-tier `domain-concept` pages for
 * recurring concepts that lack a page. Delegates to {@see ConceptSynthesizer},
 * scoped to the active tenant (R30).
 */
#[Description('Synthesize auto-tier domain-concept pages for recurring concepts in a project (concepts that appear across many docs but have no page yet). Returns how many candidates were found and which pages were created.')]
class KbSynthesizeConceptsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()->required(),
            'limit' => $schema->integer()->description('Max concept pages to create this run (1-50).'),
        ];
    }

    public function handle(Request $request, ConceptSynthesizer $synthesizer): Response
    {
        $projectKey = trim((string) $request->get('project_key'));
        if ($projectKey === '') {
            return Response::json(['error' => 'project_key is required']);
        }

        $limitRaw = $request->get('limit');
        $limit = is_numeric($limitRaw) ? max(1, min(50, (int) $limitRaw)) : null;

        $result = $synthesizer->synthesize(
            app(TenantContext::class)->current(),
            $projectKey,
            $limit,
        );

        return Response::json($result);
    }
}
