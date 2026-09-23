<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\IngestDocumentJob;
use App\Models\KbIngestBatchItem;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Upload\KbUploadStagingService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Advances a {@see KbIngestBatchItem} through queued → processing → succeeded
 * | failed by listening to Laravel's queue lifecycle events, WITHOUT touching
 * the shared {@see IngestDocumentJob} (the single ingestion path used by CLI /
 * HTTP / folder ingest too).
 *
 * The upload commit dispatches the job with
 * `metadata['kb_upload_batch_item_id']`; we recover the job from the event
 * payload and, only when that key is present, drive the item's status. Every
 * non-upload ingest job is ignored. Status mutations go through
 * {@see KbUploadStagingService::transitionItem()} so the broadcast seam +
 * batch finalization fire in one place.
 */
final class KbUploadBatchItemProgress
{
    public function __construct(private readonly KbUploadStagingService $service)
    {
    }

    public function processing(JobProcessing $event): void
    {
        $item = $this->resolveItem($event);
        if ($item === null) {
            return;
        }

        if ($item->status === KbIngestBatchItem::STATUS_QUEUED) {
            $this->service->transitionItem($item, KbIngestBatchItem::STATUS_PROCESSING);
        }
    }

    public function processed(JobProcessed $event): void
    {
        $item = $this->resolveItem($event);
        if ($item === null) {
            return;
        }

        // A new document is linked by KnowledgeDocumentUploadObserver. An
        // identical re-ingest deliberately reuses its existing version, so
        // it emits no created event and would otherwise leave this batch item
        // succeeded but without a document link for the UI. Resolve the live
        // version from the job's tenant-scoped source identity instead.
        $this->linkExistingDocument($item, $this->resolveIngestJob($event));

        // JobProcessed fires when handle() returned without throwing — the
        // reliable success signal, even on an idempotent no-op re-ingest where
        // no KnowledgeDocument::created event happens.
        if (in_array($item->status, [KbIngestBatchItem::STATUS_QUEUED, KbIngestBatchItem::STATUS_PROCESSING], true)) {
            $this->service->transitionItem($item, KbIngestBatchItem::STATUS_SUCCEEDED);
        }
    }

    private function linkExistingDocument(KbIngestBatchItem $item, ?IngestDocumentJob $job): void
    {
        if ($item->knowledge_document_id !== null || $job === null) {
            return;
        }

        $document = KnowledgeDocument::query()
            ->forTenant($job->tenantId)
            ->where('project_key', $job->projectKey)
            ->where('source_path', $job->relativePath)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($document !== null) {
            $item->forceFill(['knowledge_document_id' => $document->id])->save();
        }
    }

    public function failed(JobFailed $event): void
    {
        $item = $this->resolveItem($event);
        if ($item === null) {
            return;
        }

        if (! in_array($item->status, KbIngestBatchItem::TERMINAL, true)) {
            $this->service->transitionItem($item, KbIngestBatchItem::STATUS_FAILED, [
                'error' => 'Ingest failed: '.$event->exception->getMessage(),
            ]);
        }
    }

    private function resolveItem(JobProcessing|JobProcessed|JobFailed $event): ?KbIngestBatchItem
    {
        $job = $this->resolveIngestJob($event);
        if ($job === null) {
            return null;
        }

        $itemId = $job->metadata['kb_upload_batch_item_id'] ?? null;
        if (! is_string($itemId) || $itemId === '') {
            return null;
        }

        // R30: scope to the job's captured tenant, matching the sibling
        // KnowledgeDocumentUploadObserver's defence-in-depth. The id comes from
        // the job's own metadata (not user input), but the worker carries the
        // tenant so the two progress touch-points stay consistent.
        return KbIngestBatchItem::query()->forTenant($job->tenantId)->whereKey($itemId)->first();
    }

    private function resolveIngestJob(JobProcessing|JobProcessed|JobFailed $event): ?IngestDocumentJob
    {
        try {
            $payload = $event->job->payload();
        } catch (\Throwable) {
            return null;
        }

        $command = $payload['data']['command'] ?? null;
        // Cheap guard before unserializing arbitrary command strings.
        if (! is_string($command) || ! str_contains($command, 'IngestDocumentJob')) {
            return null;
        }

        try {
            // Restrict deserialization to IngestDocumentJob (its props are all
            // scalars/arrays — no nested objects), so a tampered or replayed
            // queue payload can't trigger PHP object injection. A disallowed
            // class unserializes to __PHP_Incomplete_Class and fails the
            // instanceof check below.
            $resolved = unserialize($command, ['allowed_classes' => [IngestDocumentJob::class]]);
        } catch (\Throwable) {
            return null;
        }

        return $resolved instanceof IngestDocumentJob ? $resolved : null;
    }
}
