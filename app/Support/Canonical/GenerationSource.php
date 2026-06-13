<?php

declare(strict_types=1);

namespace App\Support\Canonical;

/**
 * v8.11 Auto-Wiki — provenance of a canonical document's content.
 *
 *  - Human  → authored / curated by a human (the human-gated tier of ADR 0003).
 *             The authoritative, human-vouched content.
 *  - Auto   → compiled or frontmatter-enriched by the AutoWikiCompiler.
 *             Real, searchable and graph-navigable, but a SECOND-CLASS tier:
 *             the reranker firewall ranks human-`accepted` ABOVE `auto`
 *             ABOVE raw, so a human correction on the same topic always wins
 *             (the anti-hallucination guarantee). An admin can PROMOTE an
 *             `auto` doc to `human` once reviewed.
 *
 * Stored in `knowledge_documents.generation_source` as its string value.
 * Default `human` so every pre-v8.11 row keeps today's behaviour (R43 OFF path).
 */
enum GenerationSource: string
{
    case Human = 'human';
    case Auto = 'auto';

    /**
     * True when this tier is human-vouched (the authoritative tier). The
     * reranker uses this to decide whether to apply the auto-tier penalty.
     */
    public function isHumanVouched(): bool
    {
        return $this === self::Human;
    }
}
