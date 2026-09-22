<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * v8.37/W3 (ADR 0031 §6) — thrown by
 * {@see \App\Services\Kb\Review\KbReviewService::proposeCorrection()} when
 * the proposing actor has spent their `kb.review.candidates_per_hour`
 * (`KB_REVIEW_CANDIDATES_PER_HOUR`) budget for the current window.
 *
 * 429 Too Many Requests — both the HTTP and MCP surfaces convert this into
 * a client-visible refusal rather than a 500; a replayed (idempotent)
 * proposal never reaches this check (KbReviewService resolves the existing
 * candidate before touching the rate limiter).
 */
class KbReviewRateLimitedException extends HttpException
{
    public function __construct(string $actor, int $limitPerHour, ?\Throwable $previous = null)
    {
        parent::__construct(429, "Correction-proposal rate limit exceeded for {$actor}: {$limitPerHour} per hour.", $previous);
    }
}
