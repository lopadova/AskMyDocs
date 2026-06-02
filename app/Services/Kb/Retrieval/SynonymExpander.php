<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use App\Models\KbSynonym;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * v8.7/W1 — Synonym Expansion.
 *
 * Expands a search query with industry-specific synonyms registered per
 * (tenant, project) in `kb_synonyms`. The expansion is BIDIRECTIONAL and
 * works on equivalence GROUPS: a query that mentions any member of a
 * group (the anchor `term` OR one of its `synonyms`) is expanded with
 * every OTHER member of that group.
 *
 * Two consumers:
 *  - {@see expandQueryText()} appends matched synonyms to the text fed to
 *    the embedding model, so semantic recall reaches docs that only use
 *    the in-house jargon the base embedding model has never seen. This
 *    path is driver-independent (works under the SQLite test runner).
 *  - {@see expansionPhrases()} returns the matched expansion phrases for
 *    the FTS branch to OR into its tsquery (pgsql-only).
 *
 * No-op (returns the query unchanged) when the feature is disabled
 * globally (`config('kb.synonyms.enabled')`) or no enabled rows exist for
 * the active (tenant, project) — so consumers that never register a
 * synonym see identical retrieval behaviour.
 */
final class SynonymExpander
{
    public function __construct(
        private readonly ?TenantContext $tenant = null,
    ) {}

    /**
     * Cache key for a (tenant, project) synonym group set. Single source
     * of truth shared by the loader and {@see forget()} so a refactor
     * can't desync the two.
     */
    private static function cacheKey(string $tenantId, string $project): string
    {
        return "kb:synonyms:{$tenantId}:{$project}";
    }

    /**
     * Invalidate the cached synonym groups for a (tenant, project) — the
     * admin CRUD controller calls this after every create / update /
     * delete so retrieval stops using a stale group set before the TTL
     * would otherwise expire it.
     */
    public static function forget(string $tenantId, string $projectKey): void
    {
        Cache::forget(self::cacheKey($tenantId, $projectKey));
    }

    /**
     * Returns the query text enriched with any matched synonym phrases,
     * deduplicated and appended after the original query. Returns the
     * original query verbatim when expansion is disabled or yields nothing.
     */
    public function expandQueryText(string $query, ?string $projectKey): string
    {
        $phrases = $this->expansionPhrases($query, $projectKey);
        if ($phrases === []) {
            return $query;
        }

        return trim($query.' '.implode(' ', $phrases));
    }

    /**
     * Returns the de-duplicated list of expansion phrases for the query —
     * every group member NOT already present in the query, across all
     * groups any query token matched. Empty list = no expansion.
     *
     * @return list<string>
     */
    public function expansionPhrases(string $query, ?string $projectKey): array
    {
        if (! (bool) config('kb.synonyms.enabled', true)) {
            return [];
        }

        $groups = $this->groupsFor($projectKey);
        if ($groups === []) {
            return [];
        }

        $haystack = $this->normalize($query);
        $expansions = [];

        foreach ($groups as $members) {
            // Does the query mention ANY member of this equivalence group?
            $matched = false;
            foreach ($members as $member) {
                if ($member !== '' && $this->mentions($haystack, $member)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                continue;
            }

            // Add every member NOT already present in the query.
            foreach ($members as $member) {
                if ($member === '' || $this->mentions($haystack, $member)) {
                    continue;
                }
                $expansions[$member] = true;
            }
        }

        return array_keys($expansions);
    }

    /**
     * Word/phrase-boundary match of a (normalized) member against a
     * (normalized) query. Boundaries are non-letter/digit positions, so a
     * member adjacent to punctuation still matches (`k8s,` / `k8s.` /
     * `(k8s)`) while a member that is merely a substring of a larger word
     * does not (`k8s` does NOT match `k8sx`). Internal punctuation inside a
     * member (`gp-2.0`, `c++`) is treated literally via preg_quote.
     */
    private function mentions(string $haystack, string $member): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($member, '/').'(?![\p{L}\p{N}])/u';

        return preg_match($pattern, $haystack) === 1;
    }

    /**
     * Loads the enabled synonym GROUPS for the active (tenant, project).
     * Each group is a list of lowercased members (the anchor term + its
     * synonyms). Cached briefly per (tenant, project) to keep the
     * retrieval hot-path off the DB on repeated queries.
     *
     * @return list<list<string>>
     */
    private function groupsFor(?string $projectKey): array
    {
        $tenantId = ($this->tenant ?? app(TenantContext::class))->current();
        $project = $projectKey ?? '';
        $ttl = (int) config('kb.synonyms.cache_ttl_seconds', 300);

        $loader = function () use ($tenantId, $project): array {
            $rows = KbSynonym::query()
                ->forTenant($tenantId)
                ->where('project_key', $project)
                ->where('enabled', true)
                // Deterministic order so the appended expansion-phrase order
                // (and therefore the embedding input text) is stable across
                // DB engines — no "same synonyms, different embedding" drift.
                ->orderBy('term')
                ->get(['term', 'synonyms']);

            $groups = [];
            foreach ($rows as $row) {
                $members = [$this->normalize((string) $row->term)];
                foreach ((array) ($row->synonyms ?? []) as $syn) {
                    $norm = $this->normalize((string) $syn);
                    if ($norm !== '') {
                        $members[] = $norm;
                    }
                }
                // A group needs ≥2 distinct members to be meaningful.
                // Explicit predicate (NOT bare array_filter) so a legitimate
                // falsy-string member like '0' is not silently dropped
                // (Copilot review).
                $members = array_values(array_unique(
                    array_filter($members, static fn (string $m): bool => $m !== '')
                ));
                if (count($members) >= 2) {
                    $groups[] = $members;
                }
            }

            return $groups;
        };

        if ($ttl <= 0) {
            return $loader();
        }

        return Cache::remember(self::cacheKey($tenantId, $project), $ttl, $loader);
    }

    /**
     * Lowercase + collapse internal whitespace so multi-word phrases
     * match regardless of spacing/case. Punctuation is left intact (a
     * jargon token like `gp-2.0` stays addressable).
     */
    private function normalize(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return (string) preg_replace('/\s+/', ' ', $lower);
    }
}
