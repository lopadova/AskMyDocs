<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

/**
 * Immutable retrieval-time filter set (T2.1).
 *
 * Carries the rich, multi-dimensional filter shape exposed by the v3.0 chat
 * API and admin search surface. Consumed by {@see \App\Services\Kb\KbSearchService::applyFilters()}
 * to narrow the candidate set BEFORE reranking + graph expansion +
 * rejected-approach injection — filters change the population the
 * retrieval scoring runs over, not the post-hoc ranking.
 *
 * Each field defaults to its empty value so callers can build the DTO
 * incrementally (legacy `searchWithContext($q, $projectKey)` becomes
 * `RetrievalFilters(projectKeys: [$projectKey])` internally; everything
 * else stays empty so the existing query plan is preserved).
 */
final readonly class RetrievalFilters
{
    /**
     * @param  list<string>  $projectKeys     Multi-tenant scope; empty = no project filter.
     * @param  list<string>  $tagSlugs        Match documents tagged with ANY listed slug (T2.3 join).
     * @param  list<string>  $sourceTypes     SourceType::value strings (markdown/text/pdf/docx).
     * @param  list<string>  $canonicalTypes  Canonical-type tokens (decision/runbook/...).
     * @param  list<string>  $connectorTypes  Connector identifiers (local/google-drive/...). Stored
     *                                        in `metadata.connector` until v3.x adds a column —
     *                                        the DTO accepts the value but applyFilters() defers
     *                                        the actual constraint until the column lands.
     * @param  list<int>     $docIds          Explicit document-id allowlist (used by @mention in UI).
     * @param  list<string>  $folderGlobs     fnmatch globs against `source_path` (T2.4).
     * @param  list<string>  $languages       ISO 639-1 codes ('it', 'en', ...).
     * @param  ?string       $dateFrom        ISO-8601 date string applied as `>=` against `indexed_at`.
     * @param  ?string       $dateTo          ISO-8601 date string applied as `<=` against `indexed_at`.
     */
    public function __construct(
        public array $projectKeys = [],
        public array $tagSlugs = [],
        public array $sourceTypes = [],
        public array $canonicalTypes = [],
        public array $connectorTypes = [],
        public array $docIds = [],
        public array $folderGlobs = [],
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public array $languages = [],
    ) {}

    /**
     * Returns true when no filter is set — the search() hot path uses this
     * to short-circuit applyFilters() entirely so the legacy query plan
     * stays bit-for-bit identical for callers that don't pass filters.
     */
    public function isEmpty(): bool
    {
        return $this->projectKeys === []
            && $this->tagSlugs === []
            && $this->sourceTypes === []
            && $this->canonicalTypes === []
            && $this->connectorTypes === []
            && $this->docIds === []
            && $this->folderGlobs === []
            && $this->languages === []
            && $this->dateFrom === null
            && $this->dateTo === null;
    }

    /**
     * Convenience constructor for legacy single-project callers — they pass
     * `?string $projectKey` and we wrap it into a RetrievalFilters with
     * `projectKeys: [$projectKey]` (or empty when null/empty).
     */
    public static function forLegacyProject(?string $projectKey): self
    {
        if ($projectKey === null || $projectKey === '') {
            return new self();
        }
        return new self(projectKeys: [$projectKey]);
    }
}
