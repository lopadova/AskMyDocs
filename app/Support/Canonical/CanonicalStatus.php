<?php

declare(strict_types=1);

namespace App\Support\Canonical;

/**
 * Editorial status of a canonical document.
 *
 *  - Draft / Review  → visible to editors, *retrievable* by the chatbot
 *    (Review returns grounding results; it helps surface newly-curated docs).
 *  - Accepted        → the primary retrievable state.
 *  - Superseded / Deprecated / Archived
 *                    → penalized at rerank time; the penalty weight is read
 *                      from config so operators can tune the aggressiveness.
 *
 * Stored in `knowledge_documents.canonical_status` as its string value.
 */
enum CanonicalStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Accepted = 'accepted';
    case Superseded = 'superseded';
    case Deprecated = 'deprecated';
    case Archived = 'archived';

    /**
     * True if the status contributes positively to retrieval ranking.
     * Used by the reranker and retrieval services to decide whether to
     * include the doc in the primary result set or to demote/exclude it.
     */
    public function isRetrievable(): bool
    {
        return match ($this) {
            self::Accepted, self::Review => true,
            default => false,
        };
    }

    /**
     * Negative weight applied to the rerank score for demoted statuses.
     * Reads the configurable penalties from `kb.canonical.*_penalty` with
     * hard-coded fallbacks so the enum works in pure-unit test contexts
     * where the Laravel config facade is not bootstrapped.
     */
    public function penaltyWeight(): float
    {
        return match ($this) {
            self::Superseded => $this->configFloat('kb.canonical.superseded_penalty', 0.40),
            self::Deprecated => $this->configFloat('kb.canonical.deprecated_penalty', 0.40),
            self::Archived => $this->configFloat('kb.canonical.archived_penalty', 0.60),
            default => 0.0,
        };
    }

    private function configFloat(string $key, float $default): float
    {
        // Decouple from the Laravel container so this enum works in pure
        // unit tests (where the container is not booted). When the app is
        // bound we read the operator-configurable penalty; otherwise fall
        // back to the compile-time default baked into the caller.
        if (! function_exists('app') || ! function_exists('config')) {
            return $default;
        }
        try {
            if (! app()->bound('config')) {
                return $default;
            }
        } catch (\Throwable) {
            return $default;
        }
        $value = config($key, $default);
        return is_numeric($value) ? (float) $value : $default;
    }
}
