<?php

declare(strict_types=1);

namespace App\Support;

/**
 * LikeEscaper — R19 complete escaping for SQL LIKE operands.
 *
 * A LIKE pattern has THREE meta-characters, not one: `%` (any run), `_`
 * (any single char) and the escape char itself (`\`). Escaping only `%`
 * is broken escaping — `a_b` would still match `acb`. Always pair the
 * escaped operand with an explicit `ESCAPE '\'` clause, because SQLite
 * (unlike MySQL/Postgres) assigns NO default escape character.
 *
 * Usage:
 *   $term = LikeEscaper::escape($userInput);
 *   $q->whereRaw("name LIKE ? ESCAPE '\\'", ['%'.$term.'%']);
 */
final class LikeEscaper
{
    /**
     * Escape `\`, `%` and `_` for use inside a LIKE pattern.
     *
     * The escape character must be substituted FIRST so the `%`/`_`
     * escapes that follow are not themselves re-escaped.
     */
    public static function escape(string $value, string $escape = '\\'): string
    {
        return str_replace(
            [$escape, '%', '_'],
            [$escape.$escape, $escape.'%', $escape.'_'],
            $value,
        );
    }

    /**
     * Convenience: escape + wrap in `%...%` for a contains-search.
     */
    public static function contains(string $value, string $escape = '\\'): string
    {
        return '%'.self::escape($value, $escape).'%';
    }
}
