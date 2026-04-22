<?php

namespace App\Services\Kb;

use Illuminate\Support\Collection;

/**
 * Hybrid reranker that combines vector similarity with keyword
 * relevance and heading match scoring.
 *
 * Flow:
 *   1. KbSearchService over-retrieves candidates (e.g. 3x limit)
 *   2. Each candidate carries a vector_score from pgvector
 *   3. Reranker computes keyword + heading scores
 *   4. Scores are fused: alpha*vector + beta*keyword + gamma*heading
 *   5. Top-K candidates are returned
 *
 * No external API calls — runs entirely in-process.
 */
class Reranker
{
    private static array $stopWords = [
        // Italian
        'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una',
        'di', 'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra',
        'e', 'o', 'ma', 'che', 'non', 'si', 'come',
        'del', 'della', 'dello', 'dei', 'delle', 'degli',
        'al', 'alla', 'allo', 'ai', 'alle', 'agli',
        'nel', 'nella', 'nello', 'nei', 'nelle', 'negli',
        'sul', 'sulla', 'sullo', 'sui', 'sulle', 'sugli',
        // English
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
        'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'from',
        'and', 'or', 'but', 'not', 'this', 'that', 'it',
        'how', 'what', 'which', 'who', 'where', 'when',
    ];

    /**
     * Rerank a collection of chunk arrays by fused scoring.
     *
     * Each chunk must contain at minimum:
     *   - chunk_text (string)
     *   - heading_path (?string)
     *   - vector_score (float, 0-1)
     *
     * @param  Collection<int, array>  $chunks  Over-retrieved candidates.
     * @return Collection<int, array>  Top $limit chunks, sorted by rerank_score desc.
     */
    public function rerank(string $query, Collection $chunks, int $limit): Collection
    {
        if ($chunks->isEmpty()) {
            return $chunks;
        }

        if (! config('kb.reranking.enabled', true)) {
            return $chunks->take($limit)->values();
        }

        $queryTokens = $this->tokenize($query);

        if (empty($queryTokens)) {
            return $chunks->take($limit)->values();
        }

        $vectorWeight = (float) config('kb.reranking.vector_weight', 0.60);
        $keywordWeight = (float) config('kb.reranking.keyword_weight', 0.30);
        $headingWeight = (float) config('kb.reranking.heading_weight', 0.10);

        $priorityWeight = (float) config('kb.canonical.priority_weight', 0.003);

        return $chunks
            ->map(function (array $chunk) use ($queryTokens, $vectorWeight, $keywordWeight, $headingWeight, $priorityWeight) {
                $vectorScore = (float) ($chunk['vector_score'] ?? 0.0);
                $keywordScore = $this->keywordScore($queryTokens, $chunk['chunk_text'] ?? '');
                $headingScore = $this->keywordScore($queryTokens, $chunk['heading_path'] ?? '');

                $baseScore = ($vectorWeight * $vectorScore)
                    + ($keywordWeight * $keywordScore)
                    + ($headingWeight * $headingScore);

                $canonicalAdjustment = $this->canonicalAdjustment($chunk, $priorityWeight);

                $chunk['rerank_score'] = $baseScore + $canonicalAdjustment['delta'];
                $chunk['rerank_detail'] = [
                    'vector' => round($vectorScore, 4),
                    'keyword' => round($keywordScore, 4),
                    'heading' => round($headingScore, 4),
                    'base' => round($baseScore, 4),
                    'canonical_boost' => round($canonicalAdjustment['boost'], 4),
                    'canonical_penalty' => round($canonicalAdjustment['penalty'], 4),
                    'combined' => round($chunk['rerank_score'], 4),
                ];

                return $chunk;
            })
            ->sortByDesc('rerank_score')
            ->take($limit)
            ->values();
    }

    /**
     * Additive score delta from the canonical layer.
     *
     *   boost   = +priorityWeight × retrieval_priority       (0..0.30 at default weight)
     *   penalty = -configured penalty per non-retrievable status
     *
     * Non-canonical chunks get zero adjustment (delta = 0) so legacy
     * documents rank identically to pre-canonical behaviour.
     *
     * @return array{delta: float, boost: float, penalty: float}
     */
    private function canonicalAdjustment(array $chunk, float $priorityWeight): array
    {
        $doc = $chunk['document'] ?? [];
        if (! (bool) ($doc['is_canonical'] ?? false)) {
            return ['delta' => 0.0, 'boost' => 0.0, 'penalty' => 0.0];
        }

        $priority = (int) ($doc['retrieval_priority'] ?? 50);
        $boost = $priorityWeight * $priority;
        $penalty = $this->statusPenalty((string) ($doc['canonical_status'] ?? ''));

        return [
            'delta' => $boost - $penalty,
            'boost' => $boost,
            'penalty' => $penalty,
        ];
    }

    private function statusPenalty(string $status): float
    {
        if ($status === 'superseded') {
            return (float) config('kb.canonical.superseded_penalty', 0.40);
        }
        if ($status === 'deprecated') {
            return (float) config('kb.canonical.deprecated_penalty', 0.40);
        }
        if ($status === 'archived') {
            return (float) config('kb.canonical.archived_penalty', 0.60);
        }
        return 0.0;
    }

    /**
     * Compute keyword coverage score (0.0 – 1.0).
     *
     * For each query token, check if it appears in the text.
     * Score = matched_tokens / total_query_tokens.
     */
    private function keywordScore(array $queryTokens, string $text): float
    {
        if (empty($queryTokens) || $text === '') {
            return 0.0;
        }

        $textLower = mb_strtolower($text);
        $matches = 0;
        $bonusExact = 0;

        foreach ($queryTokens as $token) {
            if (mb_strpos($textLower, $token) !== false) {
                $matches++;

                // Bonus for whole-word match (not just substring)
                if (preg_match('/\b' . preg_quote($token, '/') . '\b/iu', $text)) {
                    $bonusExact++;
                }
            }
        }

        $coverage = $matches / count($queryTokens);
        $exactBonus = $bonusExact / count($queryTokens) * 0.2; // up to 0.2 extra

        return min(1.0, $coverage + $exactBonus);
    }

    /**
     * Tokenize a text string into meaningful terms.
     *
     * Removes stop words and tokens shorter than 2 characters.
     *
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $tokens,
            fn (string $t) => ! in_array($t, self::$stopWords, true) && mb_strlen($t) > 1,
        ));
    }
}
