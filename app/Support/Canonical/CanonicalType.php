<?php

declare(strict_types=1);

namespace App\Support\Canonical;

/**
 * Canonical document types — the 9 kinds of typed markdown that power the
 * canonical knowledge compilation layer (OmegaWiki-inspired).
 *
 * Each type:
 *  - lives under a conventional folder (see {@see pathPrefix()})
 *  - corresponds to a graph node kind (see {@see nodeType()})
 *  - is validated at ingestion by {@see \App\Services\Kb\Canonical\CanonicalParser}
 *
 * Stored in `knowledge_documents.canonical_type` as its string value.
 */
enum CanonicalType: string
{
    case ProjectIndex = 'project-index';
    case Module = 'module-kb';
    case Decision = 'decision';
    case Runbook = 'runbook';
    case Standard = 'standard';
    case Incident = 'incident';
    case Integration = 'integration';
    case DomainConcept = 'domain-concept';
    case RejectedApproach = 'rejected-approach';

    /**
     * Conventional folder under the KB root where docs of this type live.
     * Mirrored in config('kb.promotion.path_conventions').
     */
    public function pathPrefix(): string
    {
        return match ($this) {
            self::ProjectIndex => '.',
            self::Module => 'modules',
            self::Decision => 'decisions',
            self::Runbook => 'runbooks',
            self::Standard => 'standards',
            self::Incident => 'incidents',
            self::Integration => 'integrations',
            self::DomainConcept => 'domain-concepts',
            self::RejectedApproach => 'rejected',
        };
    }

    /**
     * Graph node label used when this document is represented as a node in
     * {@see \App\Models\KbNode}. 'project-index' collapses to 'project'.
     */
    public function nodeType(): string
    {
        return match ($this) {
            self::ProjectIndex => 'project',
            self::Module => 'module',
            self::Decision => 'decision',
            self::Runbook => 'runbook',
            self::Standard => 'standard',
            self::Incident => 'incident',
            self::Integration => 'integration',
            self::DomainConcept => 'domain-concept',
            self::RejectedApproach => 'rejected-approach',
        };
    }
}
