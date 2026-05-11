<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunking\Support;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Maps a `last_modified` timestamp to a coarse recency bucket suitable
 * for the `_derived.recency_bucket` slot of v4.5 source-aware metadata.
 *
 * Buckets are deliberately coarse — fine-grained timestamps belong in
 * `last_modified` itself; the bucket is what the reranker reads when
 * applying the recency boost (§Layer 4 of DESIGN-v4.5-W5.5).
 *
 *   this_week    -- within the last 7 days
 *   this_month   -- within the last 30 days
 *   this_quarter -- within the last 90 days
 *   older        -- anything older or unknown
 *
 * Stateless and deterministic per (`now`, `last_modified`) pair so it
 * fits cleanly inside connectors at write-time AND inside the reranker
 * at read-time without further coordination.
 */
final class RecencyBucketer
{
    public const BUCKET_WEEK = 'this_week';
    public const BUCKET_MONTH = 'this_month';
    public const BUCKET_QUARTER = 'this_quarter';
    public const BUCKET_OLDER = 'older';

    /** @var list<string> The full ordered domain — exposed for filter UIs. */
    public const ALL_BUCKETS = [
        self::BUCKET_WEEK,
        self::BUCKET_MONTH,
        self::BUCKET_QUARTER,
        self::BUCKET_OLDER,
    ];

    /**
     * Map a `last_modified` value (DateTime, ISO-8601 string, or null) to a
     * bucket. Null / unparseable input → `older` (defensive: we'd rather
     * under-boost an unknown-age doc than throw at ingest time).
     *
     * Pass an explicit `$now` for deterministic testing; otherwise the
     * current wall-clock UTC is used.
     */
    public function bucket(mixed $lastModified, ?DateTimeInterface $now = null): string
    {
        $modified = $this->parse($lastModified);
        if ($modified === null) {
            return self::BUCKET_OLDER;
        }

        $reference = $now ?? new DateTimeImmutable('now');
        $deltaDays = ($reference->getTimestamp() - $modified->getTimestamp()) / 86400;
        if ($deltaDays < 0) {
            // future-dated docs (clock skew) — treat as fresh, not older.
            return self::BUCKET_WEEK;
        }
        if ($deltaDays <= 7) {
            return self::BUCKET_WEEK;
        }
        if ($deltaDays <= 30) {
            return self::BUCKET_MONTH;
        }
        if ($deltaDays <= 90) {
            return self::BUCKET_QUARTER;
        }
        return self::BUCKET_OLDER;
    }

    private function parse(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
