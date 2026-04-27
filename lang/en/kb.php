<?php

declare(strict_types=1);

/**
 * Knowledge-base user-facing strings (English).
 *
 * The `kb.refusal.*` sub-tree carries the per-reason copy that the
 * controllers render when a refusal payload is emitted. The flat
 * `kb.no_grounded_answer` key remains as a fallback for reasons that
 * don't yet have a dedicated string (forward-compat hatch — adding a
 * new refusal reason in code without adding the lang line still
 * produces a sensible response, just with the generic copy).
 */
return [
    'no_grounded_answer' => 'I cannot find information in the provided documents to answer this question.',
    'refusal' => [
        'no_relevant_context' => 'No documents in the knowledge base match this question.',
        'llm_self_refusal' => 'The AI cannot answer this question based on the provided documents.',
    ],
];
