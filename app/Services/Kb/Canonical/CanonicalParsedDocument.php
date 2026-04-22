<?php

declare(strict_types=1);

namespace App\Services\Kb\Canonical;

use App\Support\Canonical\CanonicalStatus;
use App\Support\Canonical\CanonicalType;

/**
 * Immutable DTO produced by {@see CanonicalParser::parse()}.
 *
 * Carries the raw frontmatter array, the body text (without the frontmatter
 * block), and the strongly-typed canonical fields extracted from it. May
 * include `parseErrors` if the YAML block was malformed — validate() will
 * surface them in the ValidationResult.
 */
final class CanonicalParsedDocument
{
    /**
     * @param  array<string, mixed>        $frontmatter      Raw parsed YAML (empty on parse error)
     * @param  list<string>                $relatedSlugs     Wikilink-flavoured slugs from `related:` array
     * @param  list<string>                $supersedesSlugs  Slugs this doc supersedes
     * @param  list<string>                $supersededBySlugs Slugs superseding this doc
     * @param  list<string>                $tags
     * @param  list<string>                $owners
     * @param  list<string>                $parseErrors      YAML parse exceptions (empty on success)
     */
    public function __construct(
        public readonly array $frontmatter,
        public readonly string $body,
        public readonly ?CanonicalType $type,
        public readonly ?CanonicalStatus $status,
        public readonly ?string $slug,
        public readonly ?string $docId,
        public readonly int $retrievalPriority,
        public readonly array $relatedSlugs,
        public readonly array $supersedesSlugs,
        public readonly array $supersededBySlugs,
        public readonly array $tags,
        public readonly array $owners,
        public readonly ?string $summary,
        public readonly array $parseErrors = [],
    ) {
    }
}
