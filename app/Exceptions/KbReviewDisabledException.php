<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * v8.37/W3 (ADR 0031 §1) — thrown by every mutating entry point of
 * {@see \App\Services\Kb\Review\KbReviewService} when
 * `KB_DIGITIZATION_REVIEW_ENABLED` (config `kb.review.enabled`) is off.
 *
 * 404 Not Found — the HTTP surface treats a disabled Digitization Review
 * exactly like a route that does not exist (ADR 0031's own wording: "a
 * clean 404 when off"), never a 500 or a silent no-op (R43). The CLI
 * surface catches this and prints a friendly disabled message instead of
 * letting it bubble as an uncaught exception.
 */
class KbReviewDisabledException extends HttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(404, 'Digitization Review is disabled (set KB_DIGITIZATION_REVIEW_ENABLED=true).', $previous);
    }
}
