<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Kb\RetrievalExplainRequest;
use App\Services\Kb\Retrieval\RetrievalExplainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * v8.41/W1 (ADR 0035 §D1) — HTTP surface of the retrieval `explain` trace
 * (R44). Thin: validate → {@see RetrievalExplainService} → JSON.
 *
 * Auth: `auth:sanctum` + `tenant.authorize` + `role:admin|super-admin` (route
 * group). R32 — covered by the AdminAuthorizationMatrix
 * (`/api/admin/kb/retrieval/explain`). Flag-gated (R43): with
 * `KB_RETRIEVAL_EXPLAIN_ENABLED=false` the answer is a clean 404 with a
 * machine-readable code, never a 500.
 *
 * GET, not POST: it is a read-only diagnostic, and a GET keeps it inside the
 * matrix's GET sweep. The query is operator-typed and capped at 500 chars.
 */
final class KbRetrievalExplainController extends Controller
{
    public function __invoke(RetrievalExplainRequest $request, RetrievalExplainService $retrievalExplainService): JsonResponse
    {
        if (! $retrievalExplainService->enabled()) {
            return response()->json([
                'message' => 'Retrieval explain is disabled on this deployment.',
                'code' => 'retrieval_explain_disabled',
            ], 404);
        }

        $validated = $request->validated();

        return response()->json($retrievalExplainService->explain(
            (string) $validated['query'],
            isset($validated['project_key']) && $validated['project_key'] !== '' ? (string) $validated['project_key'] : null,
            (int) ($validated['limit'] ?? 8),
            intent: isset($validated['intent']) ? (string) $validated['intent'] : null,
            investigation: (bool) ($validated['investigation'] ?? false),
            minScore: isset($validated['min_score']) ? (float) $validated['min_score'] : null,
            lineNumbers: (bool) ($validated['line_numbers'] ?? false),
        ));
    }
}
