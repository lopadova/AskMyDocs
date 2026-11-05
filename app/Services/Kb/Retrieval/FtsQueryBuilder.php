<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

/**
 * v8.41/W1 (ADR 0035 §D3) — builds the PostgreSQL tsquery expression and its
 * positional bindings for the full-text branch of hybrid retrieval.
 *
 * Pure (no DB, no config reads beyond the explicit `$syntax` argument) so the
 * whole matrix is unit-testable on SQLite, where the full-text branch itself
 * is a no-op.
 *
 * Two syntaxes:
 *
 *  - `plain`      one `plainto_tsquery(lang, phrase)` per phrase — the legacy
 *                 behaviour, byte-identical SQL to before v8.41. Quotes and
 *                 `-negation` are ignored by Postgres in this mode.
 *  - `websearch`  per phrase, ONE of:
 *                   · `websearch_to_tsquery(lang, phrase)` for anything with
 *                     structure ("quoted phrase", -exclusion, `or`, several
 *                     words);
 *                   · `to_tsquery(lang, 'term:*')` for a bare single term
 *                     (prefix match). The term is first reduced to
 *                     `[\p{L}\p{N}]+` and bound as a parameter — it is never
 *                     concatenated into SQL (SEC-RAWSQL-001) and no tsquery
 *                     operator character can survive the reduction;
 *                   · `phraseto_tsquery(lang, term)` for a single token that
 *                     contains separators (`DEC-0054`, `src/lib/i18n.ts`,
 *                     `2026.4.10`): Postgres' parser splits it at index time
 *                     into adjacent lexemes, so it has to be matched as the
 *                     phrase those parts form.
 *
 * Every phrase (the original query plus each synonym phrase) goes through the
 * same classification and the pieces are OR-ed, exactly as the legacy builder
 * OR-ed its `plainto_tsquery` calls.
 */
final class FtsQueryBuilder
{
    public const SYNTAX_PLAIN = 'plain';
    public const SYNTAX_WEBSEARCH = 'websearch';

    /**
     * @param  list<string>  $synonymPhrases
     * @return array{0: string, 1: list<string>}  [sqlExpr, bindings]
     */
    public function build(string $lang, string $query, array $synonymPhrases = [], string $syntax = self::SYNTAX_PLAIN): array
    {
        $phrases = array_merge([$query], $synonymPhrases);

        $parts = [];
        $bindings = [];
        foreach ($phrases as $phrase) {
            [$sql, $params] = $syntax === self::SYNTAX_WEBSEARCH
                ? $this->websearchPart($lang, (string) $phrase)
                : ['plainto_tsquery(?, ?)', [$lang, (string) $phrase]];

            $parts[] = $sql;
            array_push($bindings, ...$params);
        }

        $expr = count($parts) === 1 ? $parts[0] : '('.implode(' || ', $parts).')';

        return [$expr, $bindings];
    }

    /**
     * The ranking function that pairs with the syntax: `plain` keeps `ts_rank`
     * (byte-identical), `websearch` uses cover-density `ts_rank_cd` with
     * normalisation flag 32 (rank / (rank + 1)) so the lexical score is bounded
     * 0..1 — the strong-signal test of ADR 0035 §D5 needs a bounded score.
     */
    public function rankFunction(string $syntax): string
    {
        return $syntax === self::SYNTAX_WEBSEARCH ? 'ts_rank_cd' : 'ts_rank';
    }

    /** SQL fragment appended to the rank call's argument list (`, 32`) or empty. */
    public function rankNormalisationArg(string $syntax): string
    {
        return $syntax === self::SYNTAX_WEBSEARCH ? ', 32' : '';
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function websearchPart(string $lang, string $phrase): array
    {
        $trimmed = trim($phrase);

        if ($this->isSingleToken($trimmed)) {
            $reduced = $this->reduceToWord($trimmed);

            // A token that survives intact is a plain word → prefix match.
            if ($reduced !== '' && $reduced === $trimmed) {
                return ['to_tsquery(?, ?)', [$lang, $reduced.':*']];
            }

            // A token with separators (but not a leading negation / quote) is
            // an identifier → phrase of its parts.
            if ($reduced !== '' && ! $this->hasWebsearchOperator($trimmed)) {
                return ['phraseto_tsquery(?, ?)', [$lang, $trimmed]];
            }
        }

        return ['websearch_to_tsquery(?, ?)', [$lang, $phrase]];
    }

    private function isSingleToken(string $s): bool
    {
        return $s !== '' && preg_match('/\s/u', $s) !== 1;
    }

    /**
     * Reduce to the characters Postgres' text-search parser treats as part of
     * ONE word. The underscore is deliberately excluded: it is a separator for
     * the parser, so `ERR_STOCK_0417` is indexed as three adjacent lexemes and
     * must be matched as a phrase, not as a prefix of a single word.
     */
    private function reduceToWord(string $s): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    /** A leading `-` (negation) or a quote means the caller used web-search syntax. */
    private function hasWebsearchOperator(string $token): bool
    {
        return str_starts_with($token, '-') || str_contains($token, '"');
    }
}
