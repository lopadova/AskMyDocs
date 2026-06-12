<?php

declare(strict_types=1);

namespace App\Services\Kb\Upload;

use App\Events\KbUploadItemStatusChanged;
use App\Jobs\IngestDocumentJob;
use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Support\Kb\SourceType;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Orchestrates the admin UI drag-and-drop upload lifecycle:
 * stage → review → commit (move + dispatch) → progress.
 *
 * Reuses the EXACT Artisan ingestion path on commit — one
 * {@see IngestDocumentJob::dispatchForCurrentTenant()} per file, same as
 * `kb:ingest-folder`. Nothing about parse/chunk/embed/persist is duplicated
 * here. Files are buffered on the dedicated `kb-staging` disk first, then
 * moved to the canonical `kb` disk only on commit.
 *
 * R1 — every destination path goes through {@see KbPath::normalize()}.
 * R4 — every Storage put/move/delete return value is checked.
 * R21 — commit flips status + stamps committed_at INSIDE one lockForUpdate
 *       transaction, so two concurrent commits can't both proceed.
 * R30 — every query is tenant-scoped; the trait auto-fills tenant_id on write.
 */
final class KbUploadStagingService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CanonicalParser $canonicalParser,
    ) {
    }

    public function stagingDiskName(): string
    {
        return (string) config('kb.staging.disk', 'kb-staging');
    }

    public function targetDiskName(): string
    {
        return (string) config('kb.sources.disk', 'kb');
    }

    private function pathPrefix(): string
    {
        return (string) config('kb.sources.path_prefix', '');
    }

    /**
     * Stage uploaded files into a fresh batch. Each file is streamed to the
     * staging disk (no base64 / full-buffer in memory — R3/storage-stream)
     * under an opaque {tenant}/{batch}/{item}.{ext} path so a user filename
     * can never traverse or collide. Canonical frontmatter is detected for a
     * non-blocking warning (decision 4: mixed git/server source).
     *
     * @param  list<UploadedFile>  $files
     */
    public function stage(string $projectKey, ?string $subPath, array $files, ?int $userId): KbIngestBatch
    {
        $tenantId = $this->tenant->current();

        $batch = KbIngestBatch::create([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'sub_path' => ($subPath !== null && $subPath !== '') ? KbPath::normalize($subPath) : null,
            'status' => KbIngestBatch::STATUS_STAGED,
            'created_by' => $userId,
        ]);

        $disk = Storage::disk($this->stagingDiskName());
        foreach ($files as $file) {
            $this->stageOne($batch, $file, $disk);
        }

        return $batch->load('items');
    }

    private function stageOne(KbIngestBatch $batch, UploadedFile $file, Filesystem $disk): void
    {
        $original = $file->getClientOriginalName();
        $destination = $this->destinationPath($batch, $original);

        // Resolve the type by EXTENSION first (markdown's MIME is ambiguous —
        // browsers send text/plain for .md), falling back to the MIME guess.
        $sourceType = SourceType::fromExtension($file->getClientOriginalExtension());
        if ($sourceType === SourceType::UNKNOWN) {
            $sourceType = SourceType::fromMime((string) $file->getClientMimeType());
        }

        $itemId = (string) Str::orderedUuid();

        if ($sourceType === SourceType::UNKNOWN) {
            // Defence in depth — the FormRequest already rejects these.
            $this->createItem($batch, $itemId, $original, '', $destination, (string) $file->getClientMimeType(), SourceType::UNKNOWN->value, (int) $file->getSize(), KbIngestBatchItem::STATUS_FAILED, false, null, 'Unsupported file type.');

            return;
        }

        $dir = "{$batch->tenant_id}/{$batch->id}";
        $storedName = "{$itemId}.{$this->stagingExtension($sourceType)}";
        $stored = $disk->putFileAs($dir, $file, $storedName);

        if ($stored === false) {
            $this->createItem($batch, $itemId, $original, '', $destination, $sourceType->toMime(), $sourceType->value, (int) $file->getSize(), KbIngestBatchItem::STATUS_FAILED, false, null, 'Failed to write to staging disk.');

            return;
        }

        [$isCanonical, $warning] = $this->detectCanonical($sourceType, $disk, (string) $stored);

        $this->createItem($batch, $itemId, $original, (string) $stored, $destination, $sourceType->toMime(), $sourceType->value, (int) $file->getSize(), KbIngestBatchItem::STATUS_STAGED, $isCanonical, $warning, null);
    }

    /**
     * Commit a staged batch: R21 atomic gate, then move each file to the kb
     * disk and dispatch the ingest job. Partial failures are tolerated — a
     * move failure marks only its own item failed and the others proceed.
     *
     * @param  list<string>|null  $expectedItemIds  optimistic-concurrency guard
     */
    public function commit(KbIngestBatch $batch, ?array $expectedItemIds = null): KbIngestBatch
    {
        $tenantId = $this->tenant->current();

        // Optimistic guard: the operator confirms exactly what they reviewed.
        if ($expectedItemIds !== null) {
            $current = $batch->items()->where('status', KbIngestBatchItem::STATUS_STAGED)
                ->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
            $expected = collect($expectedItemIds)->map(fn ($id) => (string) $id)->sort()->values()->all();
            if ($current !== $expected) {
                throw new ConflictHttpException('The staged file set changed since you reviewed it. Reload the batch.');
            }
        }

        // R21 — flip status + stamp committed_at under the same lock so two
        // concurrent commits can't both pass (mirrors CommandRunnerService).
        $gate = DB::transaction(function () use ($batch, $tenantId): string {
            $fresh = KbIngestBatch::query()->forTenant($tenantId)
                ->whereKey($batch->id)->lockForUpdate()->first();

            if ($fresh === null) {
                return 'missing';
            }
            if ($fresh->status !== KbIngestBatch::STATUS_STAGED || $fresh->committed_at !== null) {
                return 'already';
            }

            $fresh->update([
                'status' => KbIngestBatch::STATUS_COMMITTING,
                'committed_at' => now(),
            ]);

            return 'ok';
        });

        if ($gate === 'missing') {
            throw new ConflictHttpException('Batch no longer exists.');
        }
        if ($gate === 'already') {
            throw new ConflictHttpException('This batch was already committed.');
        }

        $batch->refresh();

        $staging = Storage::disk($this->stagingDiskName());
        $kb = Storage::disk($this->targetDiskName());

        $items = $batch->items()->where('status', KbIngestBatchItem::STATUS_STAGED)->get();
        foreach ($items as $item) {
            $this->commitOne($batch, $item, $staging, $kb);
        }

        // Under async queues the items are now `queued`; finalize is driven by
        // the queue-event listener. Under sync the jobs already ran inline, so
        // finalize here flips the batch to its terminal state immediately.
        $batch->refresh();
        if ($batch->status === KbIngestBatch::STATUS_COMMITTING) {
            $batch->update(['status' => KbIngestBatch::STATUS_PROCESSING]);
        }
        $this->finalizeBatchIfComplete($batch);

        return $batch->refresh()->load('items');
    }

    private function commitOne(KbIngestBatch $batch, KbIngestBatchItem $item, Filesystem $staging, Filesystem $kb): void
    {
        $this->transitionItem($item, KbIngestBatchItem::STATUS_MOVING);

        // 1) Move staging → kb. A failure here is a genuine MOVE failure and
        //    must not abort the rest of the batch (partial-failure tolerance).
        try {
            $storedPath = $this->storedPath($item->destination_path);

            $readStream = $staging->readStream($item->staging_path);
            if (! is_resource($readStream)) {
                throw new RuntimeException('Unable to read staged file.');
            }

            try {
                $written = $kb->writeStream($storedPath, $readStream);
            } finally {
                if (is_resource($readStream)) {
                    fclose($readStream);
                }
            }

            if ($written === false) {
                throw new RuntimeException('Unable to write to KB disk.');
            }

            // Best-effort cleanup of the staged copy; a lingering staged file
            // is swept by kb:prune-staging-batches, so a false here is logged,
            // not fatal (R4).
            if ($staging->delete($item->staging_path) === false) {
                Log::warning('kb-upload: staged file lingered after move', [
                    'batch_id' => $batch->id,
                    'item_id' => $item->id,
                    'staging_path' => $item->staging_path,
                ]);
            }
        } catch (\Throwable $e) {
            $this->transitionItem($item, KbIngestBatchItem::STATUS_FAILED, [
                'error' => 'Move failed: '.$e->getMessage(),
            ]);

            return;
        }

        // 2) Mark queued BEFORE dispatch so that under a sync queue the job's
        //    JobProcessing event (fired during dispatch) sees `queued` and the
        //    listener advances it processing→succeeded correctly.
        $this->transitionItem($item, KbIngestBatchItem::STATUS_QUEUED);

        // 3) Dispatch the SAME ingest pipeline as the CLI. Wrapped so that
        //    under the sync queue (where the job runs inline) an ingest
        //    exception fails only this item instead of aborting the loop.
        //    Async failures never throw here — they're caught by the
        //    JobFailed listener after retries exhaust.
        try {
            IngestDocumentJob::dispatchForCurrentTenant(
                projectKey: $batch->project_key,
                relativePath: $item->destination_path,
                disk: $this->targetDiskName(),
                title: pathinfo($item->original_filename, PATHINFO_FILENAME),
                metadata: ['kb_upload_batch_item_id' => $item->id],
                mimeType: $item->mime_type,
            );
        } catch (\Throwable $e) {
            $this->transitionItem($item, KbIngestBatchItem::STATUS_FAILED, [
                'error' => 'Ingest failed: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * The single mutation point for item status — also the Phase-2 broadcast
     * seam. Every status change goes through here so a future Reverb layer
     * needs only to make {@see KbUploadItemStatusChanged} broadcastable.
     *
     * @param  array{error?:string,knowledge_document_id?:int,flow_run_id?:string}  $ctx
     */
    public function transitionItem(KbIngestBatchItem $item, string $status, array $ctx = []): void
    {
        $attrs = ['status' => $status];
        if (array_key_exists('error', $ctx)) {
            $attrs['error'] = $ctx['error'];
        }
        if (array_key_exists('knowledge_document_id', $ctx)) {
            $attrs['knowledge_document_id'] = $ctx['knowledge_document_id'];
        }
        if (array_key_exists('flow_run_id', $ctx)) {
            $attrs['flow_run_id'] = $ctx['flow_run_id'];
        }

        $item->update($attrs);

        event(new KbUploadItemStatusChanged($item));

        if (in_array($status, KbIngestBatchItem::TERMINAL, true)) {
            $this->finalizeBatchIfComplete($item->batch()->first());
        }
    }

    /**
     * Remove a staged file BEFORE commit. Allowed only while the batch is
     * still `staged` (a committing/committed batch has jobs in flight).
     */
    public function removeStagedItem(KbIngestBatch $batch, KbIngestBatchItem $item): void
    {
        if ($batch->status !== KbIngestBatch::STATUS_STAGED) {
            throw new ConflictHttpException('Cannot edit a batch that is no longer staged.');
        }

        if (Storage::disk($this->stagingDiskName())->delete($item->staging_path) === false) {
            Log::warning('kb-upload: failed to delete staged file on remove', [
                'batch_id' => $batch->id,
                'item_id' => $item->id,
            ]);
        }

        $item->delete();
    }

    /**
     * Cancel a staged batch and wipe its staging directory. Allowed only
     * while `staged` (R21 lock re-checks the status inside the transaction).
     */
    public function cancel(KbIngestBatch $batch): KbIngestBatch
    {
        $tenantId = $this->tenant->current();

        $ok = DB::transaction(function () use ($batch, $tenantId): bool {
            $fresh = KbIngestBatch::query()->forTenant($tenantId)
                ->whereKey($batch->id)->lockForUpdate()->first();
            if ($fresh === null || $fresh->status !== KbIngestBatch::STATUS_STAGED) {
                return false;
            }
            $fresh->update(['status' => KbIngestBatch::STATUS_CANCELLED, 'finished_at' => now()]);

            return true;
        });

        if (! $ok) {
            throw new ConflictHttpException('Only a staged batch can be cancelled.');
        }

        if (Storage::disk($this->stagingDiskName())->deleteDirectory("{$tenantId}/{$batch->id}") === false) {
            Log::warning('kb-upload: failed to delete staging directory on cancel', [
                'batch_id' => $batch->id,
            ]);
        }

        return $batch->refresh()->load('items');
    }

    /**
     * Flip the batch to its terminal state once no item is still in flight
     * (moving/queued/processing) AND none is still merely staged. Idempotent:
     * a no-op once the batch is already terminal.
     */
    public function finalizeBatchIfComplete(?KbIngestBatch $batch): void
    {
        if ($batch === null) {
            return;
        }

        $batch->refresh();
        $settled = [
            KbIngestBatch::STATUS_COMPLETED,
            KbIngestBatch::STATUS_COMPLETED_WITH_ERRORS,
            KbIngestBatch::STATUS_CANCELLED,
            KbIngestBatch::STATUS_EXPIRED,
        ];
        if (in_array($batch->status, $settled, true)) {
            return;
        }

        $inFlight = $batch->items()->whereNotIn('status', KbIngestBatchItem::TERMINAL)->count();
        if ($inFlight > 0) {
            return;
        }

        $failed = $batch->items()->where('status', KbIngestBatchItem::STATUS_FAILED)->count();
        $batch->update([
            'status' => $failed > 0
                ? KbIngestBatch::STATUS_COMPLETED_WITH_ERRORS
                : KbIngestBatch::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);
    }

    /**
     * Logical (prefix-free) KB path that becomes knowledge_documents.source_path
     * and the relativePath handed to IngestDocumentJob. The KB_PATH_PREFIX is
     * re-applied by the read side, exactly like KbIngestController.
     */
    private function destinationPath(KbIngestBatch $batch, string $originalFilename): string
    {
        $basename = basename(str_replace('\\', '/', $originalFilename));
        $parts = array_filter(
            [$batch->sub_path, $basename],
            static fn (?string $p): bool => $p !== null && $p !== '',
        );

        return KbPath::normalize(implode('/', $parts));
    }

    /**
     * Physical path on the kb disk = KB_PATH_PREFIX + logical destination
     * (mirrors KbIngestController::prepareDocument's stored_path logic).
     */
    private function storedPath(string $destinationPath): string
    {
        $prefix = $this->pathPrefix();

        return $prefix === ''
            ? $destinationPath
            : KbPath::normalize($prefix.'/'.$destinationPath);
    }

    private function stagingExtension(SourceType $type): string
    {
        return match ($type) {
            SourceType::MARKDOWN => 'md',
            SourceType::TEXT => 'txt',
            SourceType::PDF => 'pdf',
            SourceType::DOCX => 'docx',
            default => 'bin',
        };
    }

    /**
     * Detect canonical frontmatter on a staged markdown file (decision 4 —
     * non-blocking warning). Only markdown can carry frontmatter.
     *
     * @return array{0:bool,1:?string}
     */
    private function detectCanonical(SourceType $type, Filesystem $disk, string $storedPath): array
    {
        if ($type !== SourceType::MARKDOWN) {
            return [false, null];
        }

        $content = $disk->get($storedPath);
        if (! is_string($content) || $content === '') {
            return [false, null];
        }

        $parsed = $this->canonicalParser->parse($content);
        if ($parsed === null) {
            return [false, null];
        }

        if (! $this->canonicalParser->validate($parsed)->valid) {
            return [false, null];
        }

        return [
            true,
            'This file has canonical frontmatter. Uploading here ingests it, but it will NOT be added to your git repository — the canonical source of truth stays git → GitHub Action.',
        ];
    }

    private function createItem(
        KbIngestBatch $batch,
        string $itemId,
        string $originalFilename,
        string $stagingPath,
        string $destinationPath,
        string $mimeType,
        string $sourceType,
        int $sizeBytes,
        string $status,
        bool $isCanonical,
        ?string $canonicalWarning,
        ?string $error,
    ): KbIngestBatchItem {
        $item = new KbIngestBatchItem([
            'tenant_id' => $batch->tenant_id,
            'batch_id' => $batch->id,
            'original_filename' => $originalFilename,
            'staging_path' => $stagingPath,
            'destination_path' => $destinationPath,
            'mime_type' => $mimeType,
            'source_type' => $sourceType,
            'size_bytes' => $sizeBytes,
            'status' => $status,
            'is_canonical' => $isCanonical,
            'canonical_warning' => $canonicalWarning,
            'error' => $error,
        ]);
        $item->id = $itemId;
        $item->save();

        return $item;
    }
}
