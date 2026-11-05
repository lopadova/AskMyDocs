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
     * @param  ?string       $dateFrom        ISO-8601 date string applied as `>=` against `indexed_at`.
     * @param  ?string       $dateTo          ISO-8601 date string applied as `<=` against `indexed_at`.
     * @param  list<string>  $languages       ISO 639-1 codes ('it', 'en', ...).
     * @param  ?int          $collectionId    Restrict retrieval to one Living Collection membership set.
     * @param  bool          $explain         v8.41/W1 (ADR 0035 §D1) — attach a per-result `explain` trace
     *                                        to the returned chunks. NOT a filter: it never narrows the
     *                                        candidate set, so isEmpty() ignores it and the query plan is
     *                                        unchanged. Default false = no trace, no extra work.
     * @param  ?string       $intent          v8.44/W4 (ADR 0035 §D5) — a free-text disambiguation hint ("web
     *                                        page load times" for the query "performance"). NOT a filter. It
     *                                        steers the cross-encoder and disables the strong-signal skip of
     *                                        query expansion; it is NEVER sent to the expansion model (it
     *                                        gets copied into the sub-queries as meta-language).
     * @param  bool          $skipExpansion   NOT a filter. Set by callers whose query was ALREADY written by
     *                                        a model — the recursive investigation's `kbQueries` — so typed
     *                                        query expansion does not spend a second LLM call rewriting it.
     */
    public function __construct(
        public array $projectKeys = [],
        public array $tagSlugs = [],
        public array $sourceTypes = [],
        public array $canonicalTypes = [],
        public array $connectorTypes = [],
        public array $docIds = [],
        public ?int $collectionId = null,
        public array $folderGlobs = [],
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public array $languages = [],
        public bool $explain = false,
        public ?string $intent = null,
        public bool $skipExpansion = false,
        public ?\App\Services\Kb\Retrieval\Expansion\QueryExpansion $presetExpansion = null,
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
            && $this->collectionId === null
            && $this->folderGlobs === []
            && $this->languages === []
            && $this->dateFrom === null
            && $this->dateTo === null;
    }

    /**
     * Same filters, with the per-result `explain` trace switched on.
     */
    public function withExplain(bool $explain = true): self
    {
        return $this->copy(['explain' => $explain]);
    }

    /** Same filters, with a disambiguation hint (null / blank = none). */
    public function withIntent(?string $intent): self
    {
        $intent = $intent === null ? '' : trim($intent);

        return $this->copy(['intent' => $intent === '' ? null : mb_substr($intent, 0, 300)]);
    }

    /** Same filters, with typed query expansion suppressed (the query is already model-written). */
    public function withoutExpansion(): self
    {
        return $this->copy(['skipExpansion' => true]);
    }

    /**
     * Same filters, carrying the typed variants of a user-written query document
     * (W6). NOT a filter: it replaces the model expansion, never narrows candidates.
     */
    public function withPresetExpansion(?\App\Services\Kb\Retrieval\Expansion\QueryExpansion $expansion): self
    {
        return $this->copy(['presetExpansion' => $expansion]);
    }

    /** @param  array<string,mixed>  $overrides */
    private function copy(array $overrides): self
    {
        return new self(...array_merge([
            'projectKeys' => $this->projectKeys,
            'tagSlugs' => $this->tagSlugs,
            'sourceTypes' => $this->sourceTypes,
            'canonicalTypes' => $this->canonicalTypes,
            'connectorTypes' => $this->connectorTypes,
            'docIds' => $this->docIds,
            'collectionId' => $this->collectionId,
            'folderGlobs' => $this->folderGlobs,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'languages' => $this->languages,
            'explain' => $this->explain,
            'intent' => $this->intent,
            'skipExpansion' => $this->skipExpansion,
            'presetExpansion' => $this->presetExpansion,
        ], $overrides));
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
