<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

/**
 * Jaccard-based tag-overlap scorer for the v4.5/W5.5 Reranker.
 *
 * Compares the query-derived tag set against `chunk.metadata.search_tags`
 * and returns a similarity in [0.0, 1.0]. The Reranker multiplies this
 * by `kb.reranking.tag_overlap_weight` to compose the additive boost.
 *
 *   score = |Q ∩ T| / |Q ∪ T|
 *
 * Both sets are lowercased and trimmed at compare-time so the
 * connector's casing convention doesn't trip the score.
 */
final class TagOverlapScorer
{
    /**
     * @param  list<string>  $queryTags
     * @param  list<string>  $chunkTags
     */
    public function score(array $queryTags, array $chunkTags): float
    {
        if ($queryTags === [] || $chunkTags === []) {
            return 0.0;
        }
        $q = $this->normalise($queryTags);
        $c = $this->normalise($chunkTags);
        if ($q === [] || $c === []) {
            return 0.0;
        }
        $intersect = count(array_intersect($q, $c));
        $union = count(array_unique(array_merge($q, $c)));
        if ($union === 0) {
            return 0.0;
        }
        return $intersect / $union;
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function normalise(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $clean = strtolower(trim($tag));
            if ($clean === '') {
                continue;
            }
            $out[] = $clean;
        }
        return array_values(array_unique($out));
    }
}
