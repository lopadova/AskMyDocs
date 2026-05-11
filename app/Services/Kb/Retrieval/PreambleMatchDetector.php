<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

/**
 * Detects "property-asking" queries that should preferentially surface
 * the synthetic preamble chunks the v4.5/W5.5 source-aware chunkers
 * emit (Notion property panel, Confluence page-properties macro).
 *
 * Returns 1.0 for queries matching the preamble patterns, 0.0
 * otherwise. The Reranker multiplies by
 * `kb.reranking.preamble_match_weight` (default 0.05) AND only applies
 * the boost to chunks where `metadata.page_property_panel == true` —
 * see RerankerLayer4Test for the integration shape.
 *
 * Patterns are intentionally conservative — false positives waste a
 * 0.05 boost on a non-preamble chunk; false negatives just leave the
 * boost off. Patterns are bilingual (it/en) to match the production
 * tenant mix.
 */
final class PreambleMatchDetector
{
    private const PATTERNS = [
        // English property-asking patterns
        "/^\\s*(what'?s|what is)\\s+the\\s+status\\b/iu",
        "/^\\s*who\\s+owns?\\b/iu",
        "/^\\s*when\\s+was\\b/iu",
        "/^\\s*who\\s+is\\s+the\\s+owner\\b/iu",
        "/\\bowner\\s+of\\b/iu",
        // Italian
        "/^\\s*qual\\s*'?\\s*è?\\s*lo\\s+stato\\b/iu",
        "/^\\s*chi\\s+(è\\s+)?proprietari/iu",
        "/^\\s*quando\\s+è\\b/iu",
    ];

    public function score(string $query): float
    {
        if (trim($query) === '') {
            return 0.0;
        }
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $query) === 1) {
                return 1.0;
            }
        }
        return 0.0;
    }
}
