<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiReviewer;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P7 — MCP surface (R44) of cross-model review: an agentic client has an
 * independent review-LLM audit an auto-tier page (grounding / cross-refs /
 * novelty / contradictions). Delegates to {@see AutoWikiReviewer}.
 *
 * `doc_id` is unique only per (tenant, project) (R10/R30) — pass `project_key`
 * to disambiguate when the same doc_id exists in more than one project.
 */
#[Description('Run an independent cross-model review of an auto-tier knowledge page: grounding, cross-reference validity, novelty vs existing pages, and contradictions. doc_id is unique per project, so pass project_key when it exists in more than one project of the tenant.')]
class KbWikiReviewTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'doc_id' => $schema->string()->required(),
            'project_key' => $schema->string()->description('Disambiguates doc_id when it exists in multiple projects within the tenant.'),
        ];
    }

    public function handle(Request $request, AutoWikiReviewer $reviewer): Response
    {
        $docId = trim((string) $request->get('doc_id'));
        $projectKey = trim((string) ($request->get('project_key') ?? ''));
        if ($docId === '') {
            return Response::json(['error' => 'doc_id is required']);
        }

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

        return Response::json($reviewer->review($matches->first()));
    }
}
