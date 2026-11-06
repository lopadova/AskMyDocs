<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\CrossEncoder;

/**
 * v8.43/W3 (ADR 0035 §D4) — a model that reads the (query, passage) PAIR and
 * says how well the passage answers the query. The one capability the
 * heuristic {@see \App\Services\Kb\Reranker} structurally lacks: it scores
 * overlap and metadata, never the pair.
 *
 * Implementations MUST be stateless, bounded in time and size, and fail with
 * {@see CrossEncoderUnavailableException} — never return a partial or
 * malformed answer (the stage falls back to the heuristic order on that
 * exception, loudly: R14).
 */
interface CrossEncoderReranker
{
    /** Stable lower-kebab identifier, e.g. `http-self-hosted`. Goes into cache keys and metering. */
    public function name(): string;

    /** The model identifier, goes into cache keys so a model swap cannot reuse old scores. */
    public function model(): string;

    /**
     * @param  list<string>  $passages
     * @return list<float>  one finite score in 0..1 per passage, SAME order and count as `$passages`
     *
     * @throws CrossEncoderUnavailableException on any transport, status, shape or size failure
     */
    public function score(string $query, array $passages): array;
}
