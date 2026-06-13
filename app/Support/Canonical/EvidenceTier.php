<?php

declare(strict_types=1);

namespace App\Support\Canonical;

/**
 * v8.11/P1b (AutoSci #67) — the EVIDENCE-STRENGTH axis of a knowledge document.
 *
 * Distinct from `CanonicalStatus` (how authoritative the doc is WITHIN the KB)
 * and `GenerationSource` (human vs auto provenance): this captures *what kind of
 * external evidence the doc's claims rest on*. Surfaced in the RAG prompt so the
 * model weights claims by evidence strength and flags low-confidence ones for
 * human review; surfaced in the admin UI as a badge.
 *
 * Stored in `knowledge_documents.evidence_tier` as its string value (nullable —
 * default null = "not assessed", treated as unverified for ranking).
 *
 * The taxonomy is ordered by `rank()` (higher = stronger evidence). It is the
 * generalized kernel of issue #67; the domain-specific risk-sweep / contraindication
 * checklists live OUTSIDE core in `padosoft/laravel-evidence-risk-review`.
 */
enum EvidenceTier: string
{
    case Guideline = 'guideline';        // formal guideline / standard body
    case PeerReviewed = 'peer_reviewed'; // peer-reviewed publication
    case Official = 'official';          // official vendor/org documentation
    case Preprint = 'preprint';          // preprint / not-yet-peer-reviewed
    case News = 'news';                  // reputable news / press
    case Blog = 'blog';                  // blog post / opinion
    case SearchHint = 'search_hint';     // a search snippet / hint, unconfirmed
    case Unverified = 'unverified';      // no identifiable evidence source

    /**
     * Evidence strength rank (higher = stronger). Used for prompt weighting and
     * an optional rerank tie-breaker. A null column maps to `unverified` rank.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Guideline => 80,
            self::PeerReviewed => 70,
            self::Official => 60,
            self::Preprint => 50,
            self::News => 40,
            self::Blog => 30,
            self::SearchHint => 20,
            self::Unverified => 10,
        };
    }

    /**
     * True for tiers weak enough that a claim resting ONLY on them should be
     * flagged for human review in the answer (blog / search_hint / unverified).
     */
    public function isLowConfidence(): bool
    {
        return match ($this) {
            self::Blog, self::SearchHint, self::Unverified => true,
            default => false,
        };
    }

    /**
     * Coerce a raw value (LLM output / API input) to a valid tier, or null when
     * it isn't one of the taxonomy values (so callers fall back to "not assessed").
     */
    public static function tryFromLoose(mixed $value): ?self
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
