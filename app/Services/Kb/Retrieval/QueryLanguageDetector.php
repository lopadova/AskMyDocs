<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

/**
 * v8.8/W5 — lightweight query-language detection for per-query FTS.
 *
 * Picks the PostgreSQL FTS dictionary that matches the language a query is
 * written in, so an Italian query stems with the `italian` dictionary and an
 * English one with `english` — instead of a single fixed config language.
 *
 * Deliberately dependency-free + deterministic: a small per-language stopword
 * table scores the query, and a dictionary is returned ONLY when the signal is
 * confident (a clear winner above a margin). On no/ambiguous signal it returns
 * null so the caller falls back to the configured default — R14: never
 * silently stem a query with the WRONG dictionary.
 *
 * The supported set is gated by config; only languages in
 * `kb.hybrid_search.fts_supported_languages` are considered.
 */
final class QueryLanguageDetector
{
    /**
     * Common stopwords per PostgreSQL dictionary name. Kept short + highly
     * discriminative (function words that rarely collide across languages).
     *
     * @var array<string, list<string>>
     */
    private const STOPWORDS = [
        'english' => ['the', 'is', 'are', 'how', 'what', 'do', 'does', 'can', 'with', 'and', 'for', 'of', 'to', 'in', 'where', 'why', 'a', 'an'],
        'italian' => ['il', 'lo', 'la', 'come', 'cosa', 'che', 'di', 'per', 'con', 'dove', 'perche', 'perché', 'sono', 'una', 'un', 'gli', 'le', 'qual', 'quale'],
        'french' => ['le', 'la', 'les', 'comment', 'quoi', 'que', 'de', 'pour', 'avec', 'ou', 'où', 'pourquoi', 'est', 'une', 'un', 'des', 'quel', 'quelle'],
        'german' => ['der', 'die', 'das', 'wie', 'was', 'ist', 'sind', 'mit', 'und', 'für', 'von', 'wo', 'warum', 'ein', 'eine', 'wer', 'welche'],
        'spanish' => ['el', 'la', 'los', 'como', 'cómo', 'que', 'qué', 'de', 'para', 'con', 'donde', 'dónde', 'por', 'una', 'un', 'cuál', 'porque'],
        'portuguese' => ['o', 'os', 'como', 'que', 'de', 'para', 'com', 'onde', 'porque', 'porquê', 'uma', 'um', 'qual', 'são'],
    ];

    /**
     * Detect the FTS dictionary for a query, restricted to $supported.
     * Returns null when the language can't be confidently determined.
     *
     * @param  list<string>  $supported  pg dictionary names the caller allows
     */
    public function detect(string $query, array $supported): ?string
    {
        $tokens = $this->tokenize($query);
        if ($tokens === []) {
            return null;
        }

        $scores = [];
        foreach ($supported as $lang) {
            $words = self::STOPWORDS[$lang] ?? null;
            if ($words === null) {
                continue; // unknown dictionary — not detectable here
            }
            $set = array_flip($words);
            $hits = 0;
            foreach ($tokens as $token) {
                if (isset($set[$token])) {
                    $hits++;
                }
            }
            $scores[$lang] = $hits;
        }

        return $this->confidentWinner($scores);
    }

    /**
     * A winner is confident when it has at least one stopword hit AND strictly
     * more than every other candidate (no tie). Ties / all-zero → null.
     *
     * @param  array<string, int>  $scores
     */
    private function confidentWinner(array $scores): ?string
    {
        if ($scores === []) {
            return null;
        }
        arsort($scores);
        $langs = array_keys($scores);
        $top = $langs[0];
        $topScore = $scores[$top];
        if ($topScore < 1) {
            return null;
        }
        $runnerUp = count($langs) > 1 ? $scores[$langs[1]] : 0;
        if ($topScore <= $runnerUp) {
            return null; // tie — ambiguous, fall back to default
        }

        return $top;
    }

    /**
     * @return list<string> lowercased word tokens (Unicode letters only)
     */
    private function tokenize(string $query): array
    {
        $lower = mb_strtolower(trim($query));
        $parts = preg_split('/[^\p{L}]+/u', $lower) ?: [];

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
