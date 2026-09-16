<?php

declare(strict_types=1);

namespace App\Support\Kb;

use Illuminate\Contracts\Cache\Lock as LockContract;

/**
 * Carries the CALLER's already-held {@see SourceInFlight} reservation across
 * the in-process call boundary a Flow step execution puts between the job
 * that took it and `DocumentIngestor`'s retention tail.
 *
 * `IngestDocumentJob::handle()` reserves the source for the whole
 * read+convert+commit window, but the drop this reservation must guard
 * (`DocumentIngestor::finalizeSourceRetention()`) runs several calls deeper,
 * inside `Flow::execute()`'s `persist-chunks` step — a boundary that only
 * carries serializable step input, so the live lock object cannot travel
 * through it as data. Binding an instance of this class into the container
 * right before `Flow::execute()` (and forgetting it in the same `finally`
 * that releases the reservation) lets `DocumentIngestor` resolve it
 * optionally and pass it to `dropOriginalUnderLock()`, which asserts it is
 * STILL held immediately before the destructive delete — the same
 * assert-before-destroy pattern `KbArtifactsBackfillCommand` already uses
 * with its own reservation (see `finalizeSourceRetention()`'s
 * `$sourceReservationHeld` parameter).
 *
 * Never itself reserves or releases anything — a thin carrier, nothing more.
 * `IngestDocumentJob` and `DispatchIngestFanOutStep::ingestSync()` (the two
 * callers that hold a reservation across an in-process ingest) both bind and
 * forget this around their own call into the ingestor/Flow.
 */
final class ActiveSourceReservation
{
    public function __construct(public readonly ?LockContract $lock = null) {}
}
