<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiGraphLinker;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P2 — MCP surface (R44) of auto-wiki graph canonicalization: an agentic
 * client (re)builds the navigable graph (nodes + inferred edges) for an auto-tier
 * document from its compiled cross-references. Delegates to
 * {@see AutoWikiGraphLinker}.
 *
 * `doc_id` is unique only per (tenant, project) (R10/R30): within a tenant two
 * projects can share one, so `project_key` disambiguates when needed.
 */
#[Description('Rebuild the auto-wiki graph (nodes + inferred edges) for a knowledge document from its compiled cross-references. doc_id is unique per project, so pass project_key when the same doc_id exists in more than one project of the tenant.')]
class KbRebuildWikiLinksTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'doc_id' => $schema->string()->required(),
            'project_key' => $schema->string()->description('Disambiguates doc_id when it exists in multiple projects within the tenant.'),
        ];
    }

    public function handle(Request $request, AutoWikiGraphLinker $linker): Response
    {
        $docId = trim((string) $request->get('doc_id'));
        $projectKey = trim((string) ($request->get('project_key') ?? ''));

        if ($docId === '') {
            return Response::json(['error' => 'doc_id is required']);
        }

        // R10/R30 — scope to the tenant (+ project when given); doc_id is unique
        // only per (tenant, project), so a bare doc_id can match more than one.
        $matches = KnowledgeDocument::query()
            ->forTenant(app(TenantContext::class)->current())
            ->where('doc_id', $docId)
            ->when($projectKey !== '', fn ($q) => $q->where('project_key', $projectKey))
            ->get();

        if ($matches->isEmpty()) {
            return Response::json(['error' => 'document_not_found']);
        }

        if ($matches->count() > 1) {
            return Response::json([
                'error' => 'ambiguous_doc_id',
                'message' => 'doc_id matches multiple projects in this tenant; specify project_key.',
                'candidate_project_keys' => $matches->pluck('project_key')->values()->all(),
            ]);
        }

        $result = $linker->link($matches->first());

        return Response::json([
            'doc_id' => $docId,
            'project_key' => (string) $matches->first()->project_key,
            'result' => $result,
        ]);
    }
}
