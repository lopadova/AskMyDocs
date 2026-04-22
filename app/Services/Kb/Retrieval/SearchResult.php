<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use Illuminate\Support\Collection;

/**
 * Structured output of {@see \App\Services\Kb\KbSearchService::searchWithContext()}.
 *
 * Three disjoint collections the prompt composer and the chat controller
 * consume separately:
 *
 *   - `primary`   : the reranked top-K from the base vector+FTS pipeline.
 *                   These are the chunks the LLM should ground its answer on.
 *   - `expanded`  : 1-hop graph neighbours of the primary seeds, useful as
 *                   additional structural context. Appear under a separate
 *                   "RELATED CONTEXT" block in the prompt.
 *   - `rejected`  : rejected-approach documents that correlate with the query.
 *                   Rendered under a "⚠ REJECTED APPROACHES" block so the
 *                   LLM does not re-propose dismissed options.
 *
 * `meta` carries counts + timing for logging and citation shaping.
 */
final class SearchResult
{
    public function __construct(
        public readonly Collection $primary,
        public readonly Collection $expanded,
        public readonly Collection $rejected,
        public readonly array $meta = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->primary->isEmpty()
            && $this->expanded->isEmpty()
            && $this->rejected->isEmpty();
    }

    public function totalChunks(): int
    {
        return $this->primary->count()
            + $this->expanded->count()
            + $this->rejected->count();
    }
}
