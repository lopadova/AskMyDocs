<?php

declare(strict_types=1);

namespace App\Services\Kb\Versioning;

use RuntimeException;

/**
 * v8.36 / ADR 0030 §3 — the row is committed (or already there) and points
 * at its artifact, but the bytes could not be put behind the pointer: the
 * temp write or the move was refused, or a repair of an existing pointer
 * failed. The pointer is KEPT (the version is in the repairable `missing`
 * state every reader degrades on) and the failure PROPAGATES, so the
 * ingest job retries — its identical re-ingest takes the repair path and
 * publishes the artifact once the disk is back — and a connector sync or
 * an operator command sees a failed document instead of a silently
 * degraded one (R14). `kb:artifacts-backfill` repairs the rows nobody
 * re-ingests.
 */
final class ArtifactPublishFailedException extends RuntimeException
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $disk,
        public readonly string $markdownPath,
        string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Conversion artifact could not be published for document #%d at [%s] %s (the pointer is kept as `missing`; an identical re-ingest or kb:artifacts-backfill repairs it): %s',
            $documentId,
            $disk,
            $markdownPath,
            $reason,
        ), 0, $previous);
    }
}
