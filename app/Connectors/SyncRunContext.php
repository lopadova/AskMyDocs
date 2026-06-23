<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * v8.21 (Ciclo 2) — process-scoped holder for the connector sync run currently
 * executing in this queue worker.
 *
 * `ConnectorSyncJob` runs one at a time per worker process; the
 * {@see ConnectorSyncRunRecorder} sets the active run id when the job starts
 * (queue `before` event) and reads the discovered-document count when it
 * finishes (`after`/`failing`). Between those, {@see HostIngestionBridge} bumps
 * the counter once per document handed to ingestion — giving an accurate
 * `items_discovered` without any package change.
 *
 * Bound as a SINGLETON so the count survives across the dispatch calls within a
 * single job; reset on `begin()`/`end()` so it never leaks into the next job.
 */
final class SyncRunContext
{
    private ?int $runId = null;

    private int $discovered = 0;

    public function begin(int $runId): void
    {
        $this->runId = $runId;
        $this->discovered = 0;
    }

    public function isActive(): bool
    {
        return $this->runId !== null;
    }

    public function activeRunId(): ?int
    {
        return $this->runId;
    }

    /** Called once per document the connector hands to ingestion during a run. */
    public function recordDispatch(): void
    {
        if ($this->runId !== null) {
            $this->discovered++;
        }
    }

    public function discovered(): int
    {
        return $this->discovered;
    }

    public function end(): void
    {
        $this->runId = null;
        $this->discovered = 0;
    }
}
