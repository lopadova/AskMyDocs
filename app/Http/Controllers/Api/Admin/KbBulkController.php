<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Kb\BulkDocumentIdsRequest;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Bulk operations for the admin KB explorer (multi-select toolbar):
 * soft/force delete + restore on a batch of document ids.
 *
 * Contract:
 *  - Per-id results, never all-or-nothing: unknown / cross-tenant ids
 *    are reported as `not_found` and the rest of the batch proceeds.
 *    Cross-tenant ids are deliberately indistinguishable from unknown
 *    ones (existence-hiding, R30).
 *  - 404 only when ZERO ids resolve in-tenant — the request as a whole
 *    was a no-op and the caller must know (R14).
 *  - Deletion goes through {@see DocumentDeleter} exclusively (single
 *    deletion path: graph cascade + audit + file removal). Bulk sweeps
 *    do NOT opt into the obsolescence-impact analysis, matching the
 *    deleter's documented bulk contract.
 *
 * RBAC: the route middleware (`role:admin|super-admin`) gates the
 * endpoints. These are POSTs, which the AdminAuthorizationMatrixTest
 * cannot probe (it only issues getJson) — explicit per-role 403/401
 * coverage lives in KbBulkControllerTest instead (R32).
 */
class KbBulkController extends Controller
{
    public function __construct(
        private readonly DocumentDeleter $deleter,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * POST /api/admin/kb/documents/bulk-delete
     *
     * Defaults to soft-delete; `force=true` routes every id through
     * the hard-delete cascade (chunks + graph + file + audit row).
     * Soft-deleting an already-trashed row is reported as
     * `already_trashed` instead of silently no-oping (R2).
     */
    public function bulkDelete(BulkDocumentIdsRequest $request): JsonResponse
    {
        /** @var array<int, int> $ids */
        $ids = array_map(intval(...), $request->validated()['ids']);
        $force = $request->boolean('force');

        $docs = $this->resolveBatch($ids);

        if ($docs->isEmpty()) {
            return $this->noneResolved();
        }

        $results = [];
        $summary = ['requested' => count($ids), 'deleted' => 0, 'already_trashed' => 0, 'not_found' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $doc = $docs->get($id);

            if ($doc === null) {
                $results[] = ['id' => $id, 'status' => 'not_found'];
                $summary['not_found']++;
                continue;
            }

            if (! $force && $doc->trashed()) {
                $results[] = ['id' => $id, 'status' => 'already_trashed'];
                $summary['already_trashed']++;
                continue;
            }

            try {
                $result = $this->deleter->delete($doc, $force, analyzeImpact: false);
                $results[] = [
                    'id' => $id,
                    'status' => 'deleted',
                    'mode' => $result['mode'],
                    'file_deleted' => (bool) ($result['file_deleted'] ?? false),
                ];
                $summary['deleted']++;
            } catch (Throwable $e) {
                // One broken row must not abort the rest of the batch —
                // but the failure is surfaced per-id + logged, never
                // swallowed (R14).
                Log::error('kb bulk-delete failed for document', [
                    'document_id' => $id,
                    'force' => $force,
                    'error' => $e->getMessage(),
                ]);
                $results[] = ['id' => $id, 'status' => 'failed'];
                $summary['failed']++;
            }
        }

        return response()->json([
            'ok' => true,
            'mode' => $force ? 'hard' : 'soft',
            'results' => $results,
            'summary' => $summary,
        ]);
    }

    /**
     * POST /api/admin/kb/documents/bulk-restore
     *
     * Un-deletes a batch of soft-deleted docs. Live rows are reported
     * as `not_trashed` — the bulk analogue of the single-restore 409.
     */
    public function bulkRestore(BulkDocumentIdsRequest $request): JsonResponse
    {
        /** @var array<int, int> $ids */
        $ids = array_map(intval(...), $request->validated()['ids']);

        $docs = $this->resolveBatch($ids);

        if ($docs->isEmpty()) {
            return $this->noneResolved();
        }

        $results = [];
        $summary = ['requested' => count($ids), 'restored' => 0, 'not_trashed' => 0, 'not_found' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $doc = $docs->get($id);

            if ($doc === null) {
                $results[] = ['id' => $id, 'status' => 'not_found'];
                $summary['not_found']++;
                continue;
            }

            if (! $doc->trashed()) {
                $results[] = ['id' => $id, 'status' => 'not_trashed'];
                $summary['not_trashed']++;
                continue;
            }

            try {
                $doc->restore();
                $results[] = ['id' => $id, 'status' => 'restored'];
                $summary['restored']++;
            } catch (Throwable $e) {
                Log::error('kb bulk-restore failed for document', [
                    'document_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $results[] = ['id' => $id, 'status' => 'failed'];
                $summary['failed']++;
            }
        }

        return response()->json([
            'ok' => true,
            'results' => $results,
            'summary' => $summary,
        ]);
    }

    /**
     * Resolve the requested batch in ONE query, keyed by id.
     *
     * - `withTrashed()` so force-delete can promote a soft delete and
     *   restore can see trashed rows at all (R2).
     * - `forTenant()` so another tenant's ids never resolve (R30); the
     *   100-id validation cap bounds the hydrated set (R3).
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, KnowledgeDocument>
     */
    private function resolveBatch(array $ids): Collection
    {
        return KnowledgeDocument::withTrashed()
            ->forTenant($this->tenant->current())
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    private function noneResolved(): JsonResponse
    {
        return response()->json(
            ['message' => 'None of the requested documents exist.'],
            Response::HTTP_NOT_FOUND,
        );
    }
}
