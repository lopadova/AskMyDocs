<?php

declare(strict_types=1);

namespace App\Support;

/**
 * LikeEscaper — R19 complete escaping for SQL LIKE operands.
 *
 * A LIKE pattern has THREE meta-characters, not one: `%` (any run), `_`
 * (any single char) and the escape char itself. Escaping only `%` is
 * broken escaping — `a_b` would still match `acb`. Always pair the escaped
 * operand with an explicit `ESCAPE` clause, because SQLite (unlike
 * MySQL/Postgres) assigns NO default escape character.
 *
 * ⚠ The escape character is `~`, NOT backslash. A backslash escape
 * character is a landmine on PostgreSQL via PDO: the emulated-prepare
 * parser reads the backslash-before-closing-quote as an escaped quote,
 * "swallows" the next `?` placeholder, and the statement dies with
 * `SQLSTATE[HY093]: Invalid parameter number` whenever another bound `?`
 * follows in the same query. (It silently works on SQLite, so PHPUnit
 * never catches it — only the Postgres E2E job does.) `~` is a single,
 * non-special, PDO-safe escape character valid on SQLite, Postgres and
 * MySQL alike. See ESCAPE_CHAR / ESCAPE_SQL below — always use them.
 * The architecture test NoBackslashLikeEscapeTest enforces this.
 *
 * Usage:
 *   $term = LikeEscaper::contains($userInput);
 *   $q->whereRaw('name LIKE ? '.LikeEscaper::ESCAPE_SQL, [$term]);
 */
final class LikeEscaper
{
    /** The escape character used by escape()/contains() (PDO-safe, not `\`). */
    public const ESCAPE_CHAR = '~';

    /** The SQL clause to append after a `LIKE ?` that used escape()/contains(). */
    public const ESCAPE_SQL = "ESCAPE '~'";

    /**
     * Escape the escape char, `%` and `_` for use inside a LIKE pattern.
     *
     * The escape character must be substituted FIRST so the `%`/`_`
     * escapes that follow are not themselves re-escaped.
     */
    public static function escape(string $value, string $escape = self::ESCAPE_CHAR): string
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
    public static function contains(string $value, string $escape = self::ESCAPE_CHAR): string
    {
        return '%'.self::escape($value, $escape).'%';
    }
}
