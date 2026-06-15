<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiExplorerService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P10 — MCP write surface (R44) of the Wiki Explorer: an agentic client can
 * promote an auto-tier page to the human-vouched tier (it stops being penalised
 * by the reranker firewall) or, with discard=true, soft-delete it. Delegates to
 * {@see WikiExplorerService}; never touches a human page.
 *
 * `doc_id` is unique only per (tenant, project) (R10/R30) — pass `project_key`
 * to disambiguate when the same doc_id exists in more than one project.
 */
#[Description('Promote an auto-tier knowledge page to the human-vouched (authoritative) tier, or discard=true to soft-delete it. Only acts on auto pages — never on human ones. doc_id is unique per project, so pass project_key when it exists in more than one project of the tenant.')]
class KbWikiPromoteTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'doc_id' => $schema->string()->required(),
            'project_key' => $schema->string()->description('Disambiguates doc_id when it exists in multiple projects within the tenant.'),
            'discard' => $schema->boolean()->description('Soft-delete the auto page instead of promoting it.'),
        ];
    }

    public function handle(Request $request, WikiExplorerService $explorer): Response
    {
        $docId = trim((string) $request->get('doc_id'));
        $projectKey = trim((string) ($request->get('project_key') ?? ''));
        $discard = (bool) $request->get('discard');
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

        $doc = $matches->first();
        $actor = 'mcp:kb-wiki-promote';

        return Response::json(
            $discard ? $explorer->discard($doc, $actor) : $explorer->promote($doc, $actor),
        );
    }
}
