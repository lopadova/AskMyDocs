<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\KbUploadBatchItemProgress;
use App\Models\KnowledgeDocument;
use App\Observers\KnowledgeDocumentUploadObserver;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the UI-upload progress pipeline in one place (v8.9):
 *  - KnowledgeDocument::created observer → stamps knowledge_document_id on the
 *    originating upload-batch item (best-effort deep-link).
 *  - 3 queue-event listeners → drive item status queued→processing→succeeded
 *    |failed for upload-originated IngestDocumentJob runs ONLY (every other
 *    ingest job is ignored).
 *
 * Kept separate from AppServiceProvider so the upload touch-points are
 * grep-able in one file (same posture as PiiBoundaryCoverageServiceProvider).
 */
final class KbUploadServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        KnowledgeDocument::observe(KnowledgeDocumentUploadObserver::class);

        Event::listen(JobProcessing::class, [KbUploadBatchItemProgress::class, 'processing']);
        Event::listen(JobProcessed::class, [KbUploadBatchItemProgress::class, 'processed']);
        Event::listen(JobFailed::class, [KbUploadBatchItemProgress::class, 'failed']);
    }
}
