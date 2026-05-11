<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

/**
 * Pulls candidate tags from the query (and optionally recent turn
 * context). v4.5/W5.5 uses a simple keep-the-distinctive-tokens
 * heuristic — proper TF-IDF over the corpus is parked for v4.6+ when
 * the corpus-stats table lands.
 *
 * Returns up to N tags ranked by length descending (longer terms are
 * usually more discriminative than 2-3 character function words).
 * Stop-word filtering reuses the Italian + English stop-word list the
 * Reranker already maintains.
 */
final class QueryTagExtractor
{
    private const DEFAULT_MAX_TAGS = 3;

    /** @var list<string> */
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
        'the', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
        'to', 'of', 'for', 'on', 'with', 'at', 'by', 'from',
        'and', 'or', 'but', 'not', 'this', 'that', 'it',
        'how', 'what', 'which', 'who', 'where', 'when', 'why',
    ];

    /**
     * @param  list<string>  $recentMessages
     * @return list<string>
     */
    public function extract(string $query, array $recentMessages = [], int $maxTags = self::DEFAULT_MAX_TAGS): array
    {
        $tokens = $this->tokenise($query);
        foreach ($recentMessages as $msg) {
            if (! is_string($msg) || $msg === '') {
                continue;
            }
            $tokens = array_merge($tokens, $this->tokenise($msg));
        }
        $tokens = array_values(array_unique($tokens));

        // Rank by length descending; longer terms are more discriminative
        // than 2-3 character bridge words.
        usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) - mb_strlen($a));

        return array_slice($tokens, 0, $maxTags);
    }

    /**
     * @return list<string>
     */
    private function tokenise(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $text);
        $tokens = preg_split('/\s+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $tokens,
            fn (string $t) => ! in_array($t, self::$stopWords, true) && mb_strlen($t) > 2,
        ));
    }
}
