<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

/**
 * The wall-clock budget of one OCR run (`KB_OCR_JOB_TIMEOUT`), counted from
 * the moment the driver started. A page-by-page driver bounds every process
 * it starts by what is left of it and stops — `run_too_long`, a deterministic
 * refusal the job never retries — once it is exhausted; a whole-file driver
 * caps its single timeout by it. That is what makes the run lease, the ingest
 * job timeout and the re-run lock provably longer than the work: the work
 * cannot exceed the budget, whatever a driver's per-page worst case adds up to.
 */
final class OcrRunBudget
{
    private function __construct(private readonly float $startedAt, private readonly int $seconds) {}

    public static function start(?int $seconds = null): self
    {
        return new self(microtime(true), $seconds ?? OcrService::runBudgetSeconds());
    }

    /** For tests: a budget whose clock is given, not read. */
    public static function startedAt(float $startedAt, int $seconds): self
    {
        return new self($startedAt, $seconds);
    }

    public function seconds(): int
    {
        return $this->seconds;
    }

    /** Whole seconds left, never below 0. */
    public function remaining(?float $now = null): int
    {
        $elapsed = ($now ?? microtime(true)) - $this->startedAt;

        return max(0, (int) ceil($this->seconds - $elapsed));
    }

    /** A per-process timeout that never outlives the budget (at least 1 s so a process can start). */
    public function bound(int $timeout, ?float $now = null): int
    {
        return max(1, min($timeout, $this->remaining($now)));
    }

    /**
     * @throws OcrLimitExceededException  once the budget is spent (`run_too_long`)
     */
    public function assertRemaining(string $filename, ?float $now = null): void
    {
        if ($this->remaining($now) > 0) {
            return;
        }
        throw new OcrLimitExceededException(sprintf(
            'OCR refused for "%s": the run exceeded KB_OCR_JOB_TIMEOUT (%d s) — the pages OCR\'d so far are discarded, nothing is recorded or metered.',
            $filename,
            $this->seconds,
        ), 'run_too_long');
    }
}
