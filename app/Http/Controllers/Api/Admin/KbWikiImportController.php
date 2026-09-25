<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\KbWikiImportRateLimitedException;
use App\Http\Requests\Admin\Kb\StoreKbWikiImportRequest;
use App\Models\KbWikiImportCandidate;
use App\Models\User;
use App\Services\Kb\Import\KbWikiImportService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * Admin single-document wiki-import surface (v8.38/W4c, ADR 0032 §11).
 *
 * Thin controller over the SAME {@see KbWikiImportService::importDocument()}
 * core the CLI (`kb:import-wiki`, folder-walking) and `KbImportWikiTool`
 * (MCP) use. Auth: `auth:sanctum` + `tenant.authorize` +
 * `role:admin|super-admin` (route group, same stack as
 * `KbWikiExportController`). Never writes `knowledge_documents` directly —
 * every response is a promotion candidate (paused Flow run) or a refusal.
 *
 * R43: with `kb.wiki_export.enabled` off, returns the same `{disabled:
 * true}` shape as every other action in this feature.
 */
final class KbWikiImportController extends Controller
{
    public function __construct(
        private readonly KbWikiImportService $service,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * POST /api/admin/kb/imports — validate + propose one document's
     * markdown as a promotion candidate.
     */
    public function store(StoreKbWikiImportRequest $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            // The route's own auth:sanctum middleware makes this
            // unreachable in practice; guarded defensively (R14) rather
            // than trusting the middleware silently.
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $result = $this->service->importDocument(
                $this->tenant->current(),
                (string) $request->input('project_key'),
                (string) $request->input('markdown'),
                $user,
                KbWikiImportCandidate::SOURCE_HTTP,
            );
        } catch (KbWikiImportRateLimitedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 429);
        }

        if (($result['status'] ?? null) === 'disabled') {
            return response()->json(['disabled' => true]);
        }

        if (($result['status'] ?? null) === 'invalid') {
            return response()->json(['status' => 'invalid', 'errors' => $result['errors'] ?? []], 422);
        }

        return response()->json(['data' => $result], 202);
    }
}
