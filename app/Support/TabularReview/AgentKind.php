<?php

declare(strict_types=1);

namespace App\Support\TabularReview;

/**
 * v8.19/W4 — the AGENTIC dimension of a tabular-review column.
 *
 * Orthogonal to {@see FormatType} (which constrains HOW a value is rendered):
 * `agent` declares HOW the value is COMPUTED for each row document.
 *
 *   - `extract` (default) — today's behaviour: a RAG retrieval over the row's
 *     own chunks + one batched LLM call. Backward-compatible: a column with no
 *     `agent` key resolves to Extract, so every pre-v8.19 review is unchanged.
 *   - `graph` — DETERMINISTIC, LLM-FREE: the value is computed from the
 *     canonical knowledge graph + the document's own governance columns
 *     (evidence tier, frontmatter completeness, canonical status, incoming /
 *     outgoing `kb_edges`, supersession, orphan, staleness). The column carries
 *     a `metric` key naming which governance signal to resolve. This is what
 *     turns a tabular review into an auditable "Canonical KB Governance" matrix.
 *   - `verify` — multi-step anti-hallucination: after the `extract` LLM produces
 *     the cell, a bounded second pass re-checks the value against the cited
 *     chunks and DOWNGRADES the flag (green→yellow / yellow→red) when the value
 *     is not actually supported. Costs one extra LLM call per verified column.
 *
 * Single source of truth (R23): adding a kind requires a case here + a branch
 * in TabularReviewExtractor's column router; there is no overlapping predicate.
 */
enum AgentKind: string
{
    case EXTRACT = 'extract';
    case GRAPH = 'graph';
    case VERIFY = 'verify';

    /** The default when a column omits the `agent` key (backward-compatible). */
    public static function default(): self
    {
        return self::EXTRACT;
    }

    public static function fromNullable(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::default();
        }

        return self::tryFrom($value) ?? self::default();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /** True when the value is computed deterministically from the graph (no LLM call). */
    public function isLlmFree(): bool
    {
        return $this === self::GRAPH;
    }

    public function isVerify(): bool
    {
        return $this === self::VERIFY;
    }
}
