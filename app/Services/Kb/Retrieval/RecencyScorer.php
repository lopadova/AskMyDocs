<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use App\Services\Kb\Chunking\Support\RecencyBucketer;

/**
 * Maps a chunk's `metadata.recency_bucket` (written at ingest-time by
 * the per-source chunker via RecencyBucketer) to a score in [0.0, 1.0]
 * for the Reranker's recency boost.
 *
 *   this_week    → 1.0
 *   this_month   → 0.7
 *   this_quarter → 0.4
 *   older / null → 0.1
 *
 * The score gets multiplied by `kb.reranking.recency_weight` (default
 * 0.02) so the maximum recency contribution is +0.02 — small enough
 * that it nudges ties without overpowering the semantic match.
 */
final class RecencyScorer
{
    private const SCORES = [
        RecencyBucketer::BUCKET_WEEK    => 1.0,
        RecencyBucketer::BUCKET_MONTH   => 0.7,
        RecencyBucketer::BUCKET_QUARTER => 0.4,
        RecencyBucketer::BUCKET_OLDER   => 0.1,
    ];

    public function score(?string $bucket): float
    {
        if ($bucket === null) {
            // Chunk has no source-aware recency metadata (legacy ingest
            // or generic-markdown source). No boost — recency is
            // additive sugar, not a default-on penalty.
            return 0.0;
        }
        return self::SCORES[$bucket] ?? 0.0;
    }
}
