<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KbDocAnalysis;
use App\Services\Kb\Analysis\SuggestionApplier;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P8 — MCP surface (R44) of the apply engine: apply one change/delete
 * suggestion (cross_reference or impacted) from a kb_doc_analyses row. An MCP
 * apply is an explicit (agent-driven) action; delegates to
 * {@see SuggestionApplier}, scoped to the active tenant (R30).
 */
#[Description('Apply a change/delete suggestion from a document analysis: type="cross_reference" adds a navigable link from the analyzed doc to the target slug; type="impacted" deprecates the impacted target doc. The target must be a slug the analysis actually suggested.')]
class KbApplySuggestionTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'analysis_id' => $schema->integer()->required(),
            'type' => $schema->string()->required()->description('cross_reference | impacted'),
            'target' => $schema->string()->required()->description('target slug from the analysis suggestions'),
        ];
    }

    public function handle(Request $request, SuggestionApplier $applier): Response
    {
        $type = trim((string) $request->get('type'));
        $target = trim((string) $request->get('target'));
        $analysisId = (int) $request->get('analysis_id');
        if (! in_array($type, ['cross_reference', 'impacted'], true) || $target === '' || $analysisId <= 0) {
            return Response::json(['error' => 'analysis_id, a valid type (cross_reference|impacted) and target are required']);
        }

        $analysis = KbDocAnalysis::query()
            ->forTenant(app(TenantContext::class)->current())
            ->find($analysisId);
        if ($analysis === null) {
            return Response::json(['error' => 'analysis_not_found']);
        }

        return Response::json($applier->apply($analysis, $type, $target, 'mcp'));
    }
}
