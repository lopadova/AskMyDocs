<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use RuntimeException;

/**
 * A deterministic pre-egress refusal (ADR 0029 §4): the document exceeds
 * `kb.ocr.max_pages` / `kb.ocr.max_bytes`, its page count cannot be verified
 * and the driver is remote, its bytes do not carry the signature of the
 * declared type, it is a multi-frame TIFF the driver would transcribe one
 * frame of, a rendered page is over the raster bounds, the run outlived its
 * budget, or a driver returned more figures than the caps admit — refused
 * before any driver runs or any byte leaves (or before the result is
 * stored), never retried. `reason` is the machine-readable tag the
 * estimator and the status surfaces reuse (`too_many_pages` |
 * `too_many_bytes` | `pages_uncountable` | `unrecognised_bytes` |
 * `multi_frame_image` | `rendered_page_too_large` | `run_too_long` |
 * `figure_budget_exceeded`).
 */
final class OcrLimitExceededException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
