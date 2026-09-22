<?php

declare(strict_types=1);

namespace App\Support\Kb;

use RuntimeException;

/**
 * A source is already reserved by another holder right now: proceeding
 * unprotected would mean converting this object with NO exclusion at all
 * for the rest of a potentially minutes-long conversion — exactly the gap
 * {@see SourceInFlight} exists to close.
 *
 * "Someone else holds it" is only safe to treat as "already protected"
 * while that holder is still running, and it is not in the cases this
 * guards against: two tenants converting the same physical object, a retry
 * starting under the TTL a killed worker left behind, or a deleting
 * consumer holding the key for its own removal decision. So contention is
 * NOT a degrade-to-grace case (unlike a store that cannot lock at all,
 * R43) — it is a signal the attempt must not proceed right now.
 *
 * Thrown by `SourceInFlight::reserve()`. `IngestDocumentJob` lets it
 * propagate so the job fails and the queue's own retry/backoff re-attempts
 * once the contention has likely cleared; `kb:ingest-folder --sync` records
 * it as a per-file failure like any other ingest error.
 */
final class SourceReservationContendedException extends RuntimeException
{
    public function __construct(string $disk, string $fullPath)
    {
        parent::__construct("SourceInFlight: {$disk}:{$fullPath} is already reserved by another holder; refusing to convert unprotected rather than proceeding without exclusion.");
    }
}
