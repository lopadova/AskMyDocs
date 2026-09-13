<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\OcrLimitExceededException;
use App\Services\Kb\Ocr\OcrRunBudget;
use App\Services\Kb\Ocr\OcrService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The run budget (KB_OCR_JOB_TIMEOUT) bounds every process a driver starts
 * and stops the run once spent — a deterministic `run_too_long`, never a job
 * the queue re-reserves while it is still running.
 */
final class OcrRunBudgetTest extends TestCase
{
    #[Test]
    public function every_process_is_bounded_by_what_is_left_and_the_run_stops_once_spent(): void
    {
        $budget = OcrRunBudget::startedAt(1000.0, 100);
        $this->assertSame(100, $budget->remaining(1000.0));
        $this->assertSame(60, $budget->bound(300, 1040.0), 'a per-page timeout never outlives the budget');
        $this->assertSame(30, $budget->bound(30, 1040.0), 'a shorter per-page timeout is kept');
        $this->assertSame(1, $budget->bound(300, 1100.0), 'at least one second so a process can start');
        $budget->assertRemaining('scan.pdf', 1099.5);

        try {
            $budget->assertRemaining('scan.pdf', 1100.0);
            $this->fail('a spent budget must refuse the next page');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('run_too_long', $e->reason);
            $this->assertStringContainsString('KB_OCR_JOB_TIMEOUT (100 s)', $e->getMessage());
        }
    }

    #[Test]
    public function the_budget_comes_from_config_and_caps_the_effective_worst_case(): void
    {
        config(['kb.ocr.job_timeout' => 900, 'kb.ocr.tesseract.timeout' => 300]);
        $this->assertSame(900, OcrService::runBudgetSeconds());
        $this->assertSame(900, OcrRunBudget::start()->seconds());
        $tesseract = app(\App\Services\Kb\Ocr\Drivers\TesseractOcrDriver::class);
        $this->assertSame(900, OcrService::effectiveWorstCase($tesseract, 200), 'the declared worst case is capped by the budget');
        $this->assertSame(max(OcrService::RUN_LOCK_TTL, 900 + OcrService::RUN_LOCK_MARGIN), OcrService::leaseFor($tesseract, 200));
        config(['kb.ocr.job_timeout' => 5000]);
        $this->assertSame(300 * (2 + 2), OcrService::effectiveWorstCase($tesseract, 1), 'a run below the budget keeps its own bound');

        config(['kb.ocr.job_timeout' => 5]);
        $this->assertSame(60, OcrService::runBudgetSeconds(), 'floored: a budget too small to start a run is not a budget');
    }
}
