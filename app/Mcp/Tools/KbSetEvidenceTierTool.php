<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\AutoWiki\EvidenceTierService;
use App\Support\Canonical\EvidenceTier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P1b — MCP surface (R44) of the evidence-tier capability: an agentic
 * client sets (human-equivalent override) a document's evidence tier. Writes +
 * audits via {@see EvidenceTierService}.
 *
 * `doc_id` is unique only per (tenant, project) (R10/R30): within a tenant two
 * projects can share one. `project_key` is therefore part of the identity —
 * required whenever the doc_id is ambiguous across projects in the tenant.
 */
#[Description('Set a knowledge document\'s evidence tier (one of: guideline, peer_reviewed, official, preprint, news, blog, search_hint, unverified). doc_id is unique per project, so pass project_key when the same doc_id exists in more than one project of the tenant. Writes + audits.')]
class KbSetEvidenceTierTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'doc_id' => $schema->string()->required(),
            'project_key' => $schema->string()->description('Disambiguates doc_id when it exists in multiple projects within the tenant.'),
            'evidence_tier' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request, EvidenceTierService $service): Response
    {
        $docId = trim((string) $request->get('doc_id'));
        $projectKey = trim((string) ($request->get('project_key') ?? ''));
        $tier = EvidenceTier::tryFromLoose($request->get('evidence_tier'));

        if ($docId === '' || $tier === null) {
            return Response::json([
                'error' => 'doc_id and a valid evidence_tier are required',
                'valid_tiers' => EvidenceTier::values(),
            ]);
        }

        // R10/R30 — resolve scoped to the tenant (+ project when given). doc_id
        // is unique only per (tenant, project), so a bare doc_id can match more
        // than one document; never silently pick one.
        $matches = $service->findByDocId($docId, $projectKey !== '' ? $projectKey : null);

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
        $updated = $service->setTier($doc, $tier, 'mcp');

        return Response::json([
            'doc_id' => $docId,
            'project_key' => $doc->project_key,
            'evidence_tier' => $updated->evidence_tier,
        ]);
    }
}
