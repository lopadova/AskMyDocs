<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\AdminCommandAudit;
use App\Services\Kb\Pii\DetokenizeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.23 (Ciclo 4) — MCP surface (R44 third surface) for re-identifying a
 * tokenised KB document, over the SAME {@see DetokenizeService} core as the HTTP
 * endpoint and the `kb:detokenize-document` CLI.
 *
 * This is the ONE MCP tool that can surface raw PII, so it is the most tightly
 * gated:
 *   - the host `McpToolAuthorizerAdapter` only lets `admin` / `super-admin`
 *     invoke ANY tool; AND
 *   - this tool additionally requires the `pii.detokenize` permission
 *     (held by dpo / super-admin) on the authenticated Sanctum user — `admin`
 *     lacks it, `dpo` is already filtered by the authorizer, so the NET allow-set
 *     over MCP is **super-admin only** (deliberately stricter than the HTTP
 *     endpoint's dpo+super-admin: re-identifying PII through an LLM-facing tool
 *     warrants the tightest boundary).
 *
 * Tenant-scoped (R30); requires the reversible `tokenise` strategy; every
 * completed OR rejected call writes an `admin_command_audit` row.
 */
#[Description('Re-identify (detokenise) a tokenised knowledge document: return its chunk text with the original PII restored from the per-tenant vault. Requires the tokenise strategy and the pii.detokenize permission (super-admin); every call is audited. Read-only.')]
#[IsReadOnly]
#[IsIdempotent]
class KbDetokenizeTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('Numeric id of the knowledge document to re-identify (must belong to the caller\'s tenant).')
                ->required(),
        ];
    }

    public function handle(Request $request, DetokenizeService $detokenizer): Response
    {
        if (! $detokenizer->isTokeniseActive()) {
            return Response::error('PII detokenisation requires the `tokenise` strategy.');
        }

        $documentId = (int) $request->get('document_id');
        $permission = (string) config('kb.pii_redactor.detokenize_permission', 'pii.detokenize');
        $user = auth()->user();
        $context = [
            'client_ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        $hasPermission = $user !== null && method_exists($user, 'can') && $user->can($permission);
        if (! $hasPermission) {
            $detokenizer->audit(
                AdminCommandAudit::STATUS_REJECTED,
                $user?->id,
                ['document_id' => $documentId, 'surface' => 'mcp'],
                $context,
                "Missing permission: {$permission}",
            );

            return Response::error("Forbidden: missing {$permission} permission.");
        }

        $document = $detokenizer->findDocument($documentId);
        if ($document === null) {
            return Response::error("Knowledge document #{$documentId} not found in this tenant.");
        }

        $result = $detokenizer->detokenizeDocument($document);

        $detokenizer->audit(
            AdminCommandAudit::STATUS_COMPLETED,
            $user?->id,
            ['document_id' => $documentId, 'surface' => 'mcp'],
            $context,
        );

        return Response::json([
            'document_id' => $document->id,
            'project_key' => $document->project_key,
            'token_count' => $result['token_count'],
            'resolved_count' => $result['resolved_count'],
            'unresolved_tokens' => $result['unresolved_tokens'],
            'chunks' => $result['chunks'],
        ]);
    }
}
