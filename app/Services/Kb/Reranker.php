<?php

namespace App\Services\Kb;

use App\Services\Kb\Retrieval\PreambleMatchDetector;
use App\Services\Kb\Retrieval\QueryTagExtractor;
use App\Services\Kb\Retrieval\RecencyScorer;
use App\Services\Kb\Retrieval\TagOverlapScorer;
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
    private TagOverlapScorer $tagOverlap;
    private QueryTagExtractor $queryTagExtractor;
    private RecencyScorer $recencyScorer;
    private PreambleMatchDetector $preambleDetector;

    public function __construct(
        ?TagOverlapScorer $tagOverlap = null,
        ?QueryTagExtractor $queryTagExtractor = null,
        ?RecencyScorer $recencyScorer = null,
        ?PreambleMatchDetector $preambleDetector = null,
    ) {
        // Default-construct the v4.5/W5.5 source-aware signal scorers so
        // legacy resolutions of Reranker (older test bindings, the
        // KbSearchService constructor that takes Reranker directly) keep
        // working without signature churn. All four are stateless.
        $this->tagOverlap = $tagOverlap ?? new TagOverlapScorer();
        $this->queryTagExtractor = $queryTagExtractor ?? new QueryTagExtractor();
        $this->recencyScorer = $recencyScorer ?? new RecencyScorer();
        $this->preambleDetector = $preambleDetector ?? new PreambleMatchDetector();
    }

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
    /**
     * @param  list<int>  $boostDocIds  document ids the user @mentioned;
     *                                   chunks from these docs receive an
     *                                   additive `mention_boost_weight` so
     *                                   they float to the top without
     *                                   excluding other relevant results
     *                                   (v8.1, `kb.mentions.mode = boost`).
     */
    public function rerank(string $query, Collection $chunks, int $limit, array $boostDocIds = []): Collection
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

        // O(1) membership test for the @mention boost.
        $boostSet = array_flip(array_map('intval', $boostDocIds));
        $mentionBoostWeight = (float) config('kb.reranking.mention_boost_weight', 0.50);

        $vectorWeight = (float) config('kb.reranking.vector_weight', 0.55);
        $keywordWeight = (float) config('kb.reranking.keyword_weight', 0.25);
        $headingWeight = (float) config('kb.reranking.heading_weight', 0.05);

        $priorityWeight = (float) config('kb.canonical.priority_weight', 0.003);

        // v4.5/W5.5 Layer-4 weights. All four signals additive.
        $tagOverlapWeight    = (float) config('kb.reranking.tag_overlap_weight', 0.05);
        $preambleWeight      = (float) config('kb.reranking.preamble_match_weight', 0.05);
        $recencyWeight       = (float) config('kb.reranking.recency_weight', 0.02);
        $statusActiveWeight  = (float) config('kb.reranking.status_active_weight', 0.02);

        // Query-derived tags reused across chunks (extraction is cheap
        // but doing it per-chunk wastes cycles when reranking 24+
        // candidates).
        $queryTags = $this->queryTagExtractor->extract($query);
        $preambleSignal = $this->preambleDetector->score($query);

        return $chunks
            ->map(function (array $chunk) use (
                $queryTokens,
                $vectorWeight,
                $keywordWeight,
                $headingWeight,
                $priorityWeight,
                $tagOverlapWeight,
                $preambleWeight,
                $recencyWeight,
                $statusActiveWeight,
                $queryTags,
                $preambleSignal,
                $boostSet,
                $mentionBoostWeight,
            ) {
                $vectorScore = (float) ($chunk['vector_score'] ?? 0.0);
                $keywordScore = $this->keywordScore($queryTokens, $chunk['chunk_text'] ?? '');
                $headingScore = $this->keywordScore($queryTokens, $chunk['heading_path'] ?? '');

                $baseScore = ($vectorWeight * $vectorScore)
                    + ($keywordWeight * $keywordScore)
                    + ($headingWeight * $headingScore);

                $canonicalAdjustment = $this->canonicalAdjustment($chunk, $priorityWeight);

                // v4.5/W5.5 Layer-4 signal contributions.
                $sourceMetadata = $this->chunkMetadata($chunk);
                $chunkTags = $this->chunkSearchTags($sourceMetadata);
                $tagOverlapScore = $this->tagOverlap->score($queryTags, $chunkTags);
                $recencyScore = $this->recencyScorer->score($sourceMetadata['recency_bucket'] ?? null);
                $statusActive = (bool) ($sourceMetadata['status_active'] ?? false);
                $isPreamble = (bool) ($sourceMetadata['page_property_panel'] ?? false);

                $sourceAwareDelta = ($tagOverlapWeight * $tagOverlapScore)
                    + ($preambleWeight * ($preambleSignal * ($isPreamble ? 1.0 : 0.0)))
                    + ($recencyWeight * $recencyScore)
                    + ($statusActiveWeight * ($statusActive ? 1.0 : 0.0));

                // v8.1 — additive @mention boost (kb.mentions.mode=boost).
                $mentionDelta = isset($boostSet[(int) data_get($chunk, 'document.id')])
                    ? $mentionBoostWeight
                    : 0.0;

                $chunk['rerank_score'] = $baseScore + $canonicalAdjustment['delta'] + $sourceAwareDelta + $mentionDelta;
                $chunk['rerank_detail'] = [
                    'mention_boost' => round($mentionDelta, 4),
                    'vector' => round($vectorScore, 4),
                    'keyword' => round($keywordScore, 4),
                    'heading' => round($headingScore, 4),
                    'base' => round($baseScore, 4),
                    'canonical_boost' => round($canonicalAdjustment['boost'], 4),
                    'canonical_penalty' => round($canonicalAdjustment['penalty'], 4),
                    // v4.5/W5.5 — Layer-4 visibility for the dashboard.
                    'tag_overlap' => round($tagOverlapScore, 4),
                    'preamble_match' => round($preambleSignal * ($isPreamble ? 1.0 : 0.0), 4),
                    'recency' => round($recencyScore, 4),
                    'status_active' => $statusActive,
                    'source_aware_delta' => round($sourceAwareDelta, 4),
                    'combined' => round($chunk['rerank_score'], 4),
                ];

                return $chunk;
            })
            ->sortByDesc('rerank_score')
            ->take($limit)
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    private function chunkMetadata(array $chunk): array
    {
        $meta = $chunk['metadata'] ?? [];
        return is_array($meta) ? $meta : [];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return list<string>
     */
    private function chunkSearchTags(array $metadata): array
    {
        $tags = $metadata['search_tags'] ?? [];
        if (! is_array($tags)) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn ($t) => is_string($t) ? $t : '', $tags),
            static fn (string $t): bool => $t !== '',
        ));
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
