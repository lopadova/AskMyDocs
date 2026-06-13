<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\EvidenceTierService;
use App\Support\Canonical\EvidenceTier;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.11/P1b — MCP surface (R44) of the evidence-tier capability: an agentic
 * client sets (human-equivalent override) a document's evidence tier. Writes +
 * audits via {@see EvidenceTierService}. doc_id is unique per (tenant, project).
 */
#[Description('Set a knowledge document\'s evidence tier (one of: guideline, peer_reviewed, official, preprint, news, blog, search_hint, unverified). Writes + audits.')]
class KbSetEvidenceTierTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'doc_id' => $schema->string()->required(),
            'evidence_tier' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request, EvidenceTierService $service): Response
    {
        $docId = trim((string) $request->get('doc_id'));
        $tier = EvidenceTier::tryFromLoose($request->get('evidence_tier'));

        if ($docId === '' || $tier === null) {
            return Response::json([
                'error' => 'doc_id and a valid evidence_tier are required',
                'valid_tiers' => EvidenceTier::values(),
            ]);
        }

        // R30 — doc_id is unique per (tenant, project), not globally.
        $doc = KnowledgeDocument::query()
            ->forTenant(app(TenantContext::class)->current())
            ->where('doc_id', $docId)
            ->first();

        if (! $doc) {
            return Response::json(['error' => 'document_not_found']);
        }

        $updated = $service->setTier($doc, $tier, 'mcp');

        return Response::json([
            'doc_id' => $docId,
            'evidence_tier' => $updated->evidence_tier,
        ]);
    }
}
