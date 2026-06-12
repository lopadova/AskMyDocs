<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\IngestDocumentJob;
use App\Models\KbIngestBatchItem;
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

        // JobProcessed fires when handle() returned without throwing — the
        // reliable success signal, even on an idempotent no-op re-ingest where
        // no KnowledgeDocument::created event happens.
        if (in_array($item->status, [KbIngestBatchItem::STATUS_QUEUED, KbIngestBatchItem::STATUS_PROCESSING], true)) {
            $this->service->transitionItem($item, KbIngestBatchItem::STATUS_SUCCEEDED);
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

        return KbIngestBatchItem::query()->whereKey($itemId)->first();
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
            $resolved = unserialize($command);
        } catch (\Throwable) {
            return null;
        }

        return $resolved instanceof IngestDocumentJob ? $resolved : null;
    }
}
