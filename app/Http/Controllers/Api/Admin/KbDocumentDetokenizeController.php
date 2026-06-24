<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AdminCommandAudit;
use App\Services\Kb\Pii\DetokenizeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * v8.23 (Ciclo 4) — HTTP surface for operator-driven re-identification of a
 * tokenised KB document (the document-level sibling of the chat-log
 * `LogViewerController::chatDetokenize`).
 *
 * POST /api/admin/pii/documents/{id}/detokenize
 *
 * Surfaces the original PII behind a document's `chunk_text` surrogates ONLY
 * when BOTH prerequisites hold (mirroring the chat-log path):
 *   1. The reversible `tokenise` strategy is configured (else 422).
 *   2. The caller carries the Spatie permission
 *      `config('kb.pii_redactor.detokenize_permission')` (default
 *      `pii.detokenize` — held by dpo / super-admin) — else 403.
 *
 * Every 200 / 403 writes an `admin_command_audit` row (the 422 strategy
 * preflight is a config-stage error and is intentionally not audited). The
 * lookup is tenant-scoped (R30) so an operator cannot re-identify another
 * tenant's document by id.
 */
final class KbDocumentDetokenizeController extends Controller
{
    public function __construct(
        private readonly DetokenizeService $detokenizer,
    ) {}

    public function detokenize(Request $request, int $id): JsonResponse
    {
        if (! $this->detokenizer->isTokeniseActive()) {
            return response()->json([
                'message' => 'PII detokenisation requires the `tokenise` strategy.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $permission = (string) config('kb.pii_redactor.detokenize_permission', 'pii.detokenize');
        $user = $request->user();
        $context = [
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        // Tenant-scoped lookup BEFORE the permission branch so a missing doc 404s
        // for everyone (no existence oracle), but AFTER the cheap strategy gate.
        $document = $this->detokenizer->findDocument($id);
        if ($document === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $hasPermission = $user !== null && method_exists($user, 'can') && $user->can($permission);
        if (! $hasPermission) {
            $this->detokenizer->audit(
                AdminCommandAudit::STATUS_REJECTED,
                $user?->id,
                ['document_id' => $id, 'surface' => 'http'],
                $context,
                "Missing permission: {$permission}",
            );

            return response()->json([
                'message' => "Forbidden: missing {$permission} permission.",
            ], Response::HTTP_FORBIDDEN);
        }

        $result = $this->detokenizer->detokenizeDocument($document);

        $this->detokenizer->audit(
            AdminCommandAudit::STATUS_COMPLETED,
            $user?->id,
            ['document_id' => $id, 'surface' => 'http'],
            $context,
        );

        return response()->json([
            'document_id' => $document->id,
            'project_key' => $document->project_key,
            'token_count' => $result['token_count'],
            'resolved_count' => $result['resolved_count'],
            'unresolved_tokens' => $result['unresolved_tokens'],
            'chunks' => $result['chunks'],
        ]);
    }
}
