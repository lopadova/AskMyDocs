<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

/**
 * The aggregate figure budget of ONE run (SEC-LIMITS-001): the per-figure
 * cap (`KB_OCR_MAX_FIGURE_BYTES`) bounds each image, this bounds how many a
 * run may keep and how many bytes they may add up to — every accepted
 * figure is held in memory until the run is recorded, so a document inside
 * the page and byte caps could otherwise carry an unbounded number of
 * sub-cap figures. A figure the budget does not admit is omitted (the
 * Markdown says so), never read into memory.
 */
final class OcrFigureBudget
{
    private int $count = 0;

    private int $bytes = 0;

    public function __construct(private readonly int $maxCount, private readonly int $maxBytes) {}

    public static function fromConfig(): self
    {
        return new self(
            max(1, (int) config('kb.ocr.max_figures_per_run', 200)),
            max(1, (int) config('kb.ocr.max_figures_total_bytes', 104857600)),
        );
    }

    /** Whether one more figure of `$bytes` fits the budget — and consumes it when it does. */
    public function admit(int $bytes): bool
    {
        if ($this->count + 1 > $this->maxCount || $this->bytes + $bytes > $this->maxBytes) {
            return false;
        }
        $this->count++;
        $this->bytes += $bytes;

        return true;
    }

    public function maxCount(): int
    {
        return $this->maxCount;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }
}
