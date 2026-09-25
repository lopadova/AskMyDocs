<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Kb\StoreKbWikiExportRequest;
use App\Models\KbWikiExportRequest;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\Export\KbWikiExportRequestService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin async portable-wiki export (v8.38/W4b, ADR 0032 §1/§11).
 *
 * Thin controller: idempotency + orchestration in
 * {@see KbWikiExportRequestService}, the actual export in the queued
 * {@see \App\Jobs\ExecuteKbWikiExportJob}, presentation here.
 *
 * Auth: `auth:sanctum` + `tenant.authorize` + `role:admin|super-admin`
 * (route group, same stack as `KbUploadController`). Every export request
 * is resolved through a tenant-scoped lookup (R30 — no cross-tenant IDOR),
 * UUID primary key keeps the id non-enumerable.
 *
 * R43 both states: with `kb.wiki_export.enabled` off, every action on this
 * controller returns the SAME `{disabled: true}` shape rather than letting
 * a stale id 404/500 be indistinguishable from "the feature is off".
 */
final class KbWikiExportController extends Controller
{
    public function __construct(
        private readonly KbWikiExportRequestService $service,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * POST /api/admin/kb/exports — start (or reuse, via idempotency key) an
     * async export.
     */
    public function store(StoreKbWikiExportRequest $request): JsonResponse
    {
        if (! $this->enabled()) {
            return $this->disabledResponse();
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            // The route's own auth:sanctum middleware makes this
            // unreachable in practice; guarded defensively (R14) rather
            // than trusting the middleware silently.
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $exportRequest = $this->service->requestExport(
                $this->tenant->current(),
                (string) $request->input('project_key'),
                $user,
                [
                    'format' => $request->input('format', 'llm-wiki'),
                    'include_images' => (bool) $request->input('include_images', false),
                ],
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(
            ['data' => $this->present($exportRequest)],
            $exportRequest->wasRecentlyCreated ? 202 : 200,
        );
    }

    /**
     * GET /api/admin/kb/exports/{id} — status (and, once completed, a
     * download link).
     */
    public function show(string $id): JsonResponse
    {
        if (! $this->enabled()) {
            return $this->disabledResponse();
        }

        $exportRequest = KbWikiExportRequest::query()
            ->forTenant($this->tenant->current())
            ->find($id);
        if (! $exportRequest instanceof KbWikiExportRequest) {
            return response()->json(['message' => 'Export request not found.'], 404);
        }

        return response()->json(['data' => $this->present($exportRequest)]);
    }

    /**
     * GET /api/admin/kb/exports/{id}/download — v8.38/W4b (ADR 0032 §11).
     *
     * Deliberately NOT a bypass-auth Laravel signed URL: the ADR requires
     * "every download" to re-authorize the PRINCIPAL, which a link that
     * skips the session cannot do. This route stays behind the same
     * `auth:sanctum` + `role:admin|super-admin` stack as the rest of the
     * group; the download-specific gate below is the SECOND, independent
     * check the ADR calls for — every document id the export was computed
     * against must still be visible to the CURRENT session's principal, or
     * the response is 403 `export_invalidated` rather than serving bytes a
     * stale idempotency key happened not to catch.
     */
    public function download(string $id): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        if (! $this->enabled()) {
            return $this->disabledResponse();
        }

        $exportRequest = KbWikiExportRequest::query()
            ->forTenant($this->tenant->current())
            ->find($id);
        if (! $exportRequest instanceof KbWikiExportRequest) {
            return response()->json(['message' => 'Export request not found.'], 404);
        }

        if (! $exportRequest->isDownloadable()) {
            return response()->json(['message' => 'Export is not ready or has expired.'], 409);
        }

        $recordedIds = $exportRequest->document_ids_json ?? [];
        $stillVisibleCount = KnowledgeDocument::query()
            ->forTenant($this->tenant->current())
            ->whereIn('id', $recordedIds)
            ->count();
        if ($stillVisibleCount !== count($recordedIds)) {
            return response()->json([
                'message' => 'This export is no longer valid for your current access.',
                'code' => 'export_invalidated',
            ], 403);
        }

        return Storage::disk((string) $exportRequest->storage_disk)
            ->download((string) $exportRequest->storage_path, "{$exportRequest->project_key}-wiki-export.zip");
    }

    /**
     * @return array{
     *     id: string,
     *     status: string,
     *     project_key: string,
     *     document_count: int|null,
     *     partial: bool,
     *     error_message: string|null,
     *     expires_at: string|null,
     *     completed_at: string|null,
     *     created_at: string,
     *     download_url: string|null,
     * }
     */
    private function present(KbWikiExportRequest $exportRequest): array
    {
        return [
            'id' => $exportRequest->id,
            'status' => $exportRequest->status,
            'project_key' => $exportRequest->project_key,
            'document_count' => $exportRequest->document_count,
            'partial' => $exportRequest->partial,
            'error_message' => $exportRequest->error_message,
            'expires_at' => $exportRequest->expires_at?->toIso8601String(),
            'completed_at' => $exportRequest->completed_at?->toIso8601String(),
            'created_at' => $exportRequest->created_at->toIso8601String(),
            'download_url' => $exportRequest->isDownloadable()
                ? route('api.admin.kb.exports.download', ['id' => $exportRequest->id])
                : null,
        ];
    }

    private function enabled(): bool
    {
        return (bool) config('kb.wiki_export.enabled', false);
    }

    private function disabledResponse(): JsonResponse
    {
        return response()->json(['disabled' => true], Response::HTTP_OK);
    }
}
