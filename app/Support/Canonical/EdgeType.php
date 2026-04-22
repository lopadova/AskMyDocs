<?php

declare(strict_types=1);

namespace App\Support\Canonical;

/**
 * Typed relations between canonical knowledge nodes. Stored in
 * `kb_edges.edge_type` as the string value.
 *
 * Weights tune how much each relation contributes to graph expansion at
 * retrieval time — see {@see defaultWeight()}. Operators can override per
 * edge via `kb_edges.weight`; this method only supplies the default.
 */
enum EdgeType: string
{
    case DependsOn = 'depends_on';
    case Uses = 'uses';
    case Implements = 'implements';
    case RelatedTo = 'related_to';
    case Supersedes = 'supersedes';
    case InvalidatedBy = 'invalidated_by';
    case DecisionFor = 'decision_for';
    case DocumentedBy = 'documented_by';
    case Affects = 'affects';
    case OwnedBy = 'owned_by';

    /**
     * Default weight applied to this relation when an edge row doesn't
     * specify one explicitly. Strong semantic links (a decision FOR a
     * module, an implementation relation, a supersedes chain) get 1.0;
     * structural links (depends_on, uses, affects) get 0.8; ownership /
     * documentation / invalidation get 0.7; loose related_to gets 0.5.
     */
    public function defaultWeight(): float
    {
        return match ($this) {
            self::DecisionFor, self::Implements, self::Supersedes => 1.0,
            self::DependsOn, self::Uses, self::Affects => 0.8,
            self::DocumentedBy, self::InvalidatedBy, self::OwnedBy => 0.7,
            self::RelatedTo => 0.5,
        };
    }
}
