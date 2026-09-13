<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use RuntimeException;

/**
 * A deterministic pre-egress refusal (ADR 0029 §4): the document exceeds
 * `kb.ocr.max_pages` / `kb.ocr.max_bytes`, its page count cannot be verified
 * and the driver is remote, its bytes do not carry the signature of the
 * declared type, or a rendered page is over the raster bounds — refused
 * before any driver runs or any byte leaves, never retried. `reason` is the
 * machine-readable tag the estimator and the status surfaces reuse
 * (`too_many_pages` | `too_many_bytes` | `pages_uncountable` |
 * `unrecognised_bytes` | `rendered_page_too_large`).
 */
final class OcrLimitExceededException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
