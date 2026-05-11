<?php

declare(strict_types=1);

namespace App\Connectors\Exceptions;

/**
 * v4.5/W2 (Copilot iter1 finding #5) — Raised by a paginator when the
 * `maxPages` bound is reached AND the upstream signals `has_more=true`.
 *
 * Surfacing truncation as an exception (rather than silently returning
 * partial results) means a connector's outer try/catch records a
 * concrete `errors[]` entry on the SyncResult so operators see the
 * truncation in the admin log. A silent truncation looks identical
 * to a successful sync, and the next incremental run would miss the
 * truncated tail forever.
 *
 * Caller responsibility: catch this exception, log a warning, return
 * a partial SyncResult with `errors[]` indicating truncation. Do NOT
 * let it propagate as a hard sync failure — partial data is better
 * than no data, but the metric must reflect the truncation honestly.
 */
class ConnectorPaginationLimitException extends \RuntimeException
{
    /**
     * @param  list<array<string,mixed>>  $partialResults
     */
    public function __construct(
        public readonly array $partialResults,
        public readonly int $maxPages,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Pagination limit (maxPages=%d) reached while has_more=true; %d partial results accumulated.',
                $maxPages,
                count($partialResults),
            ),
            0,
            $previous,
        );
    }
}
