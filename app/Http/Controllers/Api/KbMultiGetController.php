<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Kb\Agent\DocumentBatchReader;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * W6 (qmd parity) — HTTP surface of the batch document read (R44). Thin:
 * validate → {@see DocumentBatchReader} → JSON.
 *
 * Authenticated reader route, NOT role-gated: every document is read through
 * Eloquent under the caller's own ACL scope (R33) and the active tenant (R30),
 * so a caller only ever receives documents it could already open one by one.
 * Flag-gated (R43): `KB_MULTI_GET_ENABLED` OFF → 404 `multi_get_disabled`.
 * POST (not GET) because a selector list does not belong in a URL.
 */
final class KbMultiGetController extends Controller
{
    public function __invoke(Request $request, DocumentBatchReader $documentBatchReader, TenantContext $tenantContext): JsonResponse
    {
        if (! (bool) config('kb.multi_get.enabled', false)) {
            return response()->json([
                'code' => 'multi_get_disabled',
                'message' => 'Batch document reads are disabled on this deployment.',
            ], 404);
        }

        $validated = $request->validate([
            'selectors' => ['required', 'array', 'min:1', 'max:'.DocumentBatchReader::MAX_SELECTORS],
            'selectors.*' => ['required', 'string', 'max:512'],
            'project_key' => ['nullable', 'string', 'max:120'],
            'max_bytes' => ['nullable', 'integer', 'min:1', 'max:'.$documentBatchReader->maxBytesCeiling()],
        ]);

        return response()->json($documentBatchReader->read(
            $tenantContext->current(),
            array_map('strval', $validated['selectors']),
            isset($validated['project_key']) && $validated['project_key'] !== '' ? (string) $validated['project_key'] : null,
            isset($validated['max_bytes']) ? (int) $validated['max_bytes'] : null,
        ));
    }
}
