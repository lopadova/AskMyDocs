<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\Kb\CommitKbUploadRequest;
use App\Http\Requests\Admin\Kb\StageKbUploadRequest;
use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use App\Services\Kb\Upload\KbUploadStagingService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin drag-and-drop KB upload — stage → review → commit → poll progress.
 *
 * Thin controller: validation in the FormRequest, orchestration in
 * {@see KbUploadStagingService}, presentation here. Reuses the exact Artisan
 * ingest pipeline on commit (one IngestDocumentJob per file).
 *
 * Auth: `auth:sanctum` + `tenant.authorize` + `role:admin|super-admin`
 * (route group). Every batch/item is resolved through a tenant-scoped route
 * binding (R30 — no cross-tenant IDOR).
 *
 * R44 — DELIBERATE single-surface exception: the interactive multipart
 * stage→review→commit flow is an admin-SPA affordance, not a new ingestion
 * capability. The underlying ingest IS already tri-surface — CLI
 * `kb:ingest-folder`, HTTP `POST /api/kb/ingest`, and the MCP ingest path all
 * reach the SAME {@see \App\Jobs\IngestDocumentJob} this commit dispatches; an
 * agent ingests through those, never through a browser upload session. The PHP
 * surface also exists ({@see KbUploadStagingService} + the `kb:prune-staging-batches`
 * command). So the staging UX ships HTTP-only (no MCP tool) on purpose.
 */
final class KbUploadController extends Controller
{
    public function __construct(
        private readonly KbUploadStagingService $service,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * GET /api/admin/kb/uploads — recent batches for the active team.
     */
    public function index(): JsonResponse
    {
        $batches = KbIngestBatch::query()
            ->forTenant($this->tenant->current())
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $batches->map(fn (KbIngestBatch $b) => [
                'id' => $b->id,
                'status' => $b->status,
                'project_key' => $b->project_key,
                'sub_path' => $b->sub_path,
                'item_count' => (int) $b->items_count,
                'created_at' => $b->created_at,
                'finished_at' => $b->finished_at,
            ])->all(),
        ]);
    }

    /**
     * POST /api/admin/kb/uploads — stage uploaded files into a fresh batch.
     */
    public function store(StageKbUploadRequest $request): JsonResponse
    {
        /** @var list<\Illuminate\Http\UploadedFile> $files */
        $files = array_values($request->file('files', []));

        $batch = $this->service->stage(
            projectKey: (string) $request->input('project_key'),
            subPath: $request->input('sub_path'),
            files: $files,
            userId: $request->user()?->id,
        );

        return response()->json($this->presentBatch($batch), 201);
    }

    /**
     * GET /api/admin/kb/uploads/{uploadBatch} — inspect a batch + items.
     */
    public function show(KbIngestBatch $uploadBatch): JsonResponse
    {
        return response()->json($this->presentBatch($uploadBatch));
    }

    /**
     * GET /api/admin/kb/uploads/{uploadBatch}/status — poll progress.
     * Same additive shape as show() so the FE can poll one contract (R27).
     */
    public function status(KbIngestBatch $uploadBatch): JsonResponse
    {
        return response()->json($this->presentBatch($uploadBatch));
    }

    /**
     * POST /api/admin/kb/uploads/{uploadBatch}/commit — move + dispatch ingest.
     */
    public function commit(CommitKbUploadRequest $request, KbIngestBatch $uploadBatch): JsonResponse
    {
        /** @var list<string>|null $expected */
        $expected = $request->input('expected_item_ids');

        $batch = $this->service->commit($uploadBatch, $expected);

        return response()->json($this->presentBatch($batch), 202);
    }

    /**
     * POST /api/admin/kb/uploads/{uploadBatch}/cancel — cancel a staged batch.
     */
    public function cancel(KbIngestBatch $uploadBatch): JsonResponse
    {
        $batch = $this->service->cancel($uploadBatch);

        return response()->json($this->presentBatch($batch));
    }

    /**
     * DELETE /api/admin/kb/uploads/{uploadBatch}/items/{uploadItem} — remove a
     * staged file before commit.
     */
    public function destroyItem(KbIngestBatch $uploadBatch, KbIngestBatchItem $uploadItem): JsonResponse
    {
        if ($uploadItem->batch_id !== $uploadBatch->id) {
            throw new NotFoundHttpException('Item not found in this batch.');
        }

        $this->service->removeStagedItem($uploadBatch, $uploadItem);

        return response()->json(null, 204);
    }

    /**
     * @return array{batch: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function presentBatch(KbIngestBatch $batch): array
    {
        $batch->loadMissing('items');
        $items = $batch->items;

        return [
            'batch' => [
                'id' => $batch->id,
                'status' => $batch->status,
                'project_key' => $batch->project_key,
                'sub_path' => $batch->sub_path,
                'counts' => $this->counts($items),
                'committed_at' => $batch->committed_at,
                'finished_at' => $batch->finished_at,
                'created_at' => $batch->created_at,
            ],
            'items' => $items->map(fn (KbIngestBatchItem $i) => $this->presentItem($i))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentItem(KbIngestBatchItem $item): array
    {
        return [
            'id' => $item->id,
            'original_filename' => $item->original_filename,
            'destination_path' => $item->destination_path,
            'size_bytes' => $item->size_bytes,
            'mime_type' => $item->mime_type,
            'source_type' => $item->source_type,
            'status' => $item->status,
            'is_canonical' => $item->is_canonical,
            'canonical_warning' => $item->canonical_warning,
            'error' => $item->error,
            'knowledge_document_id' => $item->knowledge_document_id,
        ];
    }

    /**
     * Per-status item counts, all six keys always present (R27 additive).
     *
     * @param  \Illuminate\Support\Collection<int, KbIngestBatchItem>  $items
     * @return array<string, int>
     */
    private function counts(\Illuminate\Support\Collection $items): array
    {
        $base = [
            KbIngestBatchItem::STATUS_STAGED => 0,
            KbIngestBatchItem::STATUS_MOVING => 0,
            KbIngestBatchItem::STATUS_QUEUED => 0,
            KbIngestBatchItem::STATUS_PROCESSING => 0,
            KbIngestBatchItem::STATUS_SUCCEEDED => 0,
            KbIngestBatchItem::STATUS_FAILED => 0,
        ];

        foreach ($items as $item) {
            if (array_key_exists($item->status, $base)) {
                $base[$item->status]++;
            }
        }

        return $base;
    }
}
