<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use RuntimeException;

/**
 * A document exceeds `kb.ocr.max_pages` / `kb.ocr.max_bytes`, or its page
 * count cannot be verified and the driver is remote: refused before any
 * driver runs (ADR 0029 §4). `reason` is the machine-readable tag the
 * estimator and the status surfaces reuse
 * (`too_many_pages` | `too_many_bytes` | `pages_uncountable`).
 */
final class OcrLimitExceededException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
