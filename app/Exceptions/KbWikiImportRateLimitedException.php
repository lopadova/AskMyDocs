<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * v8.38/W4c (ADR 0032 §11) — thrown by
 * {@see \App\Services\Kb\Import\KbWikiImportService::importDocument()} when
 * the importing actor has spent their `kb.wiki_export.import_candidates_per_hour`
 * (`KB_WIKI_IMPORT_CANDIDATES_PER_HOUR`) budget for the current window.
 *
 * 429 Too Many Requests — every surface (CLI, HTTP, MCP) converts this into
 * a client-visible refusal rather than a 500; a replayed (idempotent)
 * proposal never reaches this check (the service resolves the existing
 * candidate's flow run before touching the rate limiter), mirroring
 * {@see KbReviewRateLimitedException}'s exact posture for OCR corrections.
 */
class KbWikiImportRateLimitedException extends HttpException
{
    public function __construct(string $actorIdentity, int $limitPerHour, ?\Throwable $previous = null)
    {
        parent::__construct(429, "Wiki-import candidate rate limit exceeded for {$actorIdentity}: {$limitPerHour} per hour.", $previous);
    }
}
