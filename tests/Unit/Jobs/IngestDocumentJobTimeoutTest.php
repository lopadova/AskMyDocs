<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\IngestDocumentJob;
use App\Services\Kb\Ocr\Drivers\TesseractOcrDriver;
use App\Services\Kb\Ocr\OcrService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The queue timeout of an ingest job is consistent with the configured
 * driver's declared worst case for a document that may OCR — never the
 * 300 s default that would kill a Docling call or a page-by-page run its
 * lease still reserves — and stays the default everywhere OCR does not run
 * (R43: OFF path first).
 */
final class IngestDocumentJobTimeoutTest extends TestCase
{
    private function job(?string $mime): IngestDocumentJob
    {
        return new IngestDocumentJob(projectKey: 'p', relativePath: 'a', disk: 'kb', mimeType: $mime);
    }

    #[Test]
    public function off_every_job_keeps_the_default_timeout(): void
    {
        config(['kb.ocr.enabled' => false, 'kb.ocr.driver' => 'tesseract']);
        $this->assertSame(OcrService::DEFAULT_JOB_TIMEOUT, $this->job('application/pdf')->timeout);
        $this->assertSame(OcrService::DEFAULT_JOB_TIMEOUT, $this->job('image/png')->timeout);
        $this->assertSame(OcrService::DEFAULT_JOB_TIMEOUT, $this->job(null)->timeout);
    }

    #[Test]
    public function on_an_ocr_able_document_gets_the_drivers_worst_case_and_text_keeps_the_default(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'tesseract', 'kb.ocr.max_pages' => 200, 'kb.ocr.tesseract.timeout' => 300, 'kb.ocr.job_timeout' => 3600]);
        $expected = OcrService::leaseFor(app(TesseractOcrDriver::class), 200);
        $this->assertGreaterThan(OcrService::DEFAULT_JOB_TIMEOUT, $expected);
        // Bounded by the run budget the driver enforces, never the sum of
        // 200 per-page timeouts: the queue's retry_after only has to exceed this.
        $this->assertSame(3600 + OcrService::RUN_LOCK_MARGIN, $expected);
        $this->assertSame($expected, $this->job('application/pdf')->timeout);
        $this->assertSame($expected, $this->job('image/jpeg; charset=binary')->timeout);
        $this->assertSame(OcrService::DEFAULT_JOB_TIMEOUT, $this->job('text/markdown')->timeout);
        $this->assertSame(OcrService::DEFAULT_JOB_TIMEOUT, $this->job(null)->timeout);

        config(['kb.ocr.driver' => 'docling', 'kb.ocr.docling.timeout' => 600]);
        $this->assertSame(max(OcrService::RUN_LOCK_TTL, 600 + OcrService::RUN_LOCK_MARGIN), $this->job('application/pdf')->timeout);
    }

    #[Test]
    public function a_queue_retry_after_shorter_than_the_job_timeout_is_logged_at_dispatch(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'tesseract', 'kb.ocr.job_timeout' => 3600, 'queue.default' => 'redis', 'queue.connections.redis.retry_after' => 330]);
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'retry_after'));
        $this->assertSame(3600 + OcrService::RUN_LOCK_MARGIN, $this->job('application/pdf')->timeout);

        config(['queue.connections.redis.retry_after' => 3600 + OcrService::RUN_LOCK_MARGIN + 1]);
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->never();
        $this->job('application/pdf');
    }

    #[Test]
    public function on_an_unresolvable_driver_keeps_the_default_because_the_run_is_refused_deterministically(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'no-such-driver']);
        $this->assertSame(OcrService::DEFAULT_JOB_TIMEOUT, $this->job('application/pdf')->timeout);
    }
}
