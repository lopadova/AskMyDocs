<?php

declare(strict_types=1);

namespace App\Connectors;

use Carbon\Carbon;

/**
 * v4.5/W1 — Immutable outcome of a connector sync run (full or
 * incremental).
 *
 * Returned by {@see ConnectorInterface::syncFull()} +
 * {@see ConnectorInterface::syncIncremental()} so the framework's job
 * runner can record `last_sync_at`, surface per-connector telemetry to
 * the admin UI, and persist any partial errors to
 * `connector_installations.error_json`.
 *
 * `errors` is a list of human-readable messages; populated entries do
 * NOT necessarily fail the sync (a connector may successfully ingest
 * 95 of 100 docs and report 5 fetch errors). The job runner marks the
 * installation as `errored` only when the connector throws — not when
 * it returns a SyncResult with a non-empty errors array.
 */
final class SyncResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly int $documentsAdded,
        public readonly int $documentsUpdated,
        public readonly int $documentsRemoved,
        public readonly array $errors,
        public readonly Carbon $completedAt,
    ) {}

    public static function empty(): self
    {
        return new self(
            documentsAdded: 0,
            documentsUpdated: 0,
            documentsRemoved: 0,
            errors: [],
            completedAt: Carbon::now(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'documents_added' => $this->documentsAdded,
            'documents_updated' => $this->documentsUpdated,
            'documents_removed' => $this->documentsRemoved,
            'errors' => $this->errors,
            'completed_at' => $this->completedAt->toIso8601String(),
        ];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function totalChanged(): int
    {
        return $this->documentsAdded + $this->documentsUpdated + $this->documentsRemoved;
    }
}
