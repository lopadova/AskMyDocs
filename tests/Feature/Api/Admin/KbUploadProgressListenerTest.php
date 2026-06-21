<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Jobs\IngestDocumentJob;
use App\Listeners\KbUploadBatchItemProgress;
use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * v8.9 — the queue-event listener that drives upload item progress.
 *
 * Asserts the lifecycle advances ONLY for IngestDocumentJob runs carrying an
 * upload batch-item id, that a plain (CLI/HTTP) ingest job is ignored, and
 * that the batch finalizes once every item is terminal.
 */
final class KbUploadProgressListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_then_processed_advances_item_and_finalizes_batch(): void
    {
        [$batch, $item] = $this->seedQueuedItem();
        $listener = app(KbUploadBatchItemProgress::class);

        $listener->processing(new JobProcessing('sync', $this->fakeJob($item->id)));
        $this->assertSame(KbIngestBatchItem::STATUS_PROCESSING, $item->fresh()->status);

        $listener->processed(new JobProcessed('sync', $this->fakeJob($item->id)));
        $this->assertSame(KbIngestBatchItem::STATUS_SUCCEEDED, $item->fresh()->status);

        // Single item, now succeeded → batch completes.
        $this->assertSame(KbIngestBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->finished_at);
    }

    public function test_failed_marks_item_failed_and_finalizes_with_errors(): void
    {
        [$batch, $item] = $this->seedQueuedItem();
        $listener = app(KbUploadBatchItemProgress::class);

        $listener->failed(new JobFailed('sync', $this->fakeJob($item->id), new RuntimeException('boom')));

        $item->refresh();
        $this->assertSame(KbIngestBatchItem::STATUS_FAILED, $item->status);
        $this->assertStringContainsString('boom', (string) $item->error);
        $this->assertSame(KbIngestBatch::STATUS_COMPLETED_WITH_ERRORS, $batch->fresh()->status);
    }

    public function test_non_upload_ingest_job_is_ignored(): void
    {
        [$batch, $item] = $this->seedQueuedItem();
        $listener = app(KbUploadBatchItemProgress::class);

        // An IngestDocumentJob with NO upload metadata (a CLI/HTTP ingest).
        $plain = new IngestDocumentJob('eng', 'a.md', 'kb', null, [], 'text/markdown', 'default');
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['data' => ['command' => serialize($plain)]]);

        $listener->processed(new JobProcessed('sync', $job));

        // Untouched — still queued, batch still processing.
        $this->assertSame(KbIngestBatchItem::STATUS_QUEUED, $item->fresh()->status);
        $this->assertSame(KbIngestBatch::STATUS_PROCESSING, $batch->fresh()->status);
    }

    /**
     * @return array{0: KbIngestBatch, 1: KbIngestBatchItem}
     */
    private function seedQueuedItem(): array
    {
        $batch = KbIngestBatch::create([
            'project_key' => 'engineering',
            'status' => KbIngestBatch::STATUS_PROCESSING,
        ]);
        $item = new KbIngestBatchItem([
            'tenant_id' => 'default',
            'batch_id' => $batch->id,
            'original_filename' => 'a.md',
            'staging_path' => 'default/'.$batch->id.'/x.md',
            'destination_path' => 'a.md',
            'mime_type' => 'text/markdown',
            'source_type' => 'markdown',
            'size_bytes' => 3,
            'status' => KbIngestBatchItem::STATUS_QUEUED,
        ]);
        $item->save();

        return [$batch, $item];
    }

    private function fakeJob(string $itemId): Job
    {
        $ingestJob = new IngestDocumentJob('engineering', 'a.md', 'kb', null, ['kb_upload_batch_item_id' => $itemId], 'text/markdown', 'default');
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['data' => ['command' => serialize($ingestJob)]]);

        return $job;
    }
}
