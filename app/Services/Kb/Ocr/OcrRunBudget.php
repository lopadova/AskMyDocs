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

    /**
     * A per-process timeout that never outlives the budget. Once the budget
     * is spent there is no "one more second": the terminal `run_too_long`
     * refusal is raised here, so a whole-file or remote caller that sizes
     * its single call from `bound()` — without a preceding
     * `assertRemaining()` — can never start work or send bytes after
     * KB_OCR_JOB_TIMEOUT has expired.
     *
     * @throws OcrLimitExceededException  once the budget is spent (`run_too_long`)
     */
    public function bound(int $timeout, ?float $now = null, string $filename = 'the document'): int
    {
        $remaining = $this->remaining($now);
        if ($remaining <= 0) {
            throw $this->spent($filename);
        }

        return max(1, min($timeout, $remaining));
    }

    /**
     * A process or provider call that hit its timeout is the same terminal
     * refusal: the same page would time out again, so the job never retries
     * it (`run_too_long`, never a generic error re-run three times).
     */
    public static function timedOut(string $filename, string $what, int $timeout): OcrLimitExceededException
    {
        return new OcrLimitExceededException(sprintf(
            'OCR refused for "%s": %s exceeded its timeout (%d s, bounded by KB_OCR_JOB_TIMEOUT) — nothing is recorded or metered.',
            $filename,
            $what,
            $timeout,
        ), 'run_too_long');
    }

    /**
     * @throws OcrLimitExceededException  once the budget is spent (`run_too_long`)
     */
    public function assertRemaining(string $filename, ?float $now = null): void
    {
        if ($this->remaining($now) > 0) {
            return;
        }
        throw $this->spent($filename);
    }

    private function spent(string $filename): OcrLimitExceededException
    {
        return new OcrLimitExceededException(sprintf(
            'OCR refused for "%s": the run exceeded KB_OCR_JOB_TIMEOUT (%d s) — the pages OCR\'d so far are discarded, nothing is recorded or metered.',
            $filename,
            $this->seconds,
        ), 'run_too_long');
    }
}
