<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use Illuminate\Support\Collection;

/**
 * Structured output of {@see \App\Services\Kb\KbSearchService::searchWithContext()}.
 *
 * Four disjoint collections the prompt composer and the chat controller
 * consume separately:
 *
 *   - `primary`   : the reranked top-K from the base vector+FTS pipeline.
 *                   These are the chunks the LLM should ground its answer on.
 *   - `runnerUp`  : the next 15 candidates that were CONSIDERED but did NOT
 *                   make the top-K cut (v8.0/W3.1 "Why-not-cited"). Each
 *                   chunk carries a `reason` key explaining WHY it was
 *                   demoted (`below_rerank_threshold` /
 *                   `outside_context_window` etc.). NOT fed to the LLM —
 *                   surfaced only in the chat UI "Considered but not used"
 *                   tab. R27 additive contract: legacy consumers that
 *                   don't know about this field keep working.
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
        public readonly ?Collection $runnerUp = null,
    ) {
    }

    /**
     * R27 additive contract: legacy callers built before W3.1 ship without
     * a runner_up collection — return an empty Collection so the FE can
     * iterate it uniformly.
     */
    public function runnerUp(): Collection
    {
        return $this->runnerUp ?? collect();
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
