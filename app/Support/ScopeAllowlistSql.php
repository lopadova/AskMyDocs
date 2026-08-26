<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Translates `scope_allowlist` folder globs into portable SQL so the
 * permission can be enforced *inside* the query instead of after it.
 *
 * Why this exists: `KbPath::matchesAnyGlob()` is the authoritative matcher
 * and compiles a glob to PCRE — `**` becomes `.*`, `*` becomes `[^/]*`,
 * `?` becomes `[^/]`. Applying it needs the rows in PHP, which is why the
 * allowlist arm of `User::hasDocumentAccess()` used to run only in the
 * policy. The retrieval hot path never calls the policy, so the arm was
 * simply absent there (H8's sibling — see AccessScopeScope).
 *
 * The translation is exact for every glob shape that occurs in practice:
 *
 *   - no cross-segment wildcard at all — e.g. "hr" then a one-star
 *     segment, or "docs" then "one-star dot md" — becomes a LIKE pattern
 *     plus an exact separator count, because one-star and "?" never cross
 *     a slash, so the path has exactly as many segments as the glob.
 *   - a cross-segment wildcard with no one-star anywhere — e.g. "hr" then
 *     two-star — becomes a LIKE pattern alone, because "%" and ".*" have
 *     the same reach.
 *
 * The one shape it cannot pin exactly mixes both: a one-star segment AND a
 * two-star segment in the same glob. The one-star needs a per-segment
 * bound that a whole-string separator count cannot express. There it falls
 * back to the literal prefix before the first wildcard, which is a
 * *superset* — it never hides a document the user may read, but may admit
 * one they may not, so for that shape `User::hasDocumentAccess()` remains
 * the authoritative gate. Callers needing certainty for exotic globs
 * should check {@see self::isExact()}.
 *
 * (Glob examples are spelled out in words above rather than written
 * literally: a star immediately followed by a slash would close this
 * docblock.)
 *
 * Separator counting uses `length()`/`replace()`, which SQLite (tests) and
 * PostgreSQL (production) both implement with the same semantics.
 */
final class ScopeAllowlistSql
{
    /**
     * True when {@see apply()} enforces `$glob` exactly rather than as a
     * superset.
     */
    public static function isExact(string $glob): bool
    {
        $tokens = explode('**', $glob);

        return count($tokens) === 1 || ! self::hasSingleSegmentWildcard($tokens);
    }

    /**
     * OR a single glob's predicate onto the given builder.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $builder
     */
    public static function apply(EloquentBuilder|QueryBuilder $builder, string $column, string $glob): void
    {
        $tokens = explode('**', $glob);
        $hasDoubleStar = count($tokens) > 1;

        if ($hasDoubleStar && self::hasSingleSegmentWildcard($tokens)) {
            // Not exactly expressible — narrow to the literal prefix.
            $builder->orWhereRaw(
                self::likeExpression($column),
                [self::escapeLike(self::literalPrefix($glob)).'%'],
            );

            return;
        }

        $pattern = self::toLikePattern($tokens);

        if ($hasDoubleStar) {
            // `**` → `%` has the same reach as `.*`; nothing more to pin.
            $builder->orWhereRaw(self::likeExpression($column), [$pattern]);

            return;
        }

        // No `**`: every wildcard stays inside one segment, so an exact
        // separator count turns the LIKE (whose `%` *does* cross `/`)
        // back into segment-aware matching.
        $separators = substr_count($glob, '/');

        $builder->orWhere(function ($q) use ($column, $pattern, $separators): void {
            $q->whereRaw(self::likeExpression($column), [$pattern])
                ->whereRaw(
                    "(length({$column}) - length(replace({$column}, '/', ''))) = ?",
                    [$separators],
                );
        });
    }

    /**
     * `LIKE ? ESCAPE '\'` — spelled out because SQLite has no default
     * escape character, so an unescaped clause would read the backslashes
     * {@see escapeLike()} adds as literal text and silently *deny* a path
     * containing `_` (which is most of them). PostgreSQL accepts the same
     * clause, so one expression serves tests and production.
     *
     * `$column` is always a qualified column name supplied by the caller,
     * never user input.
     */
    private static function likeExpression(string $column): string
    {
        return "{$column} LIKE ? ESCAPE '\\'";
    }

    /**
     * @param  array<int,string>  $tokens  Glob split on `**`.
     */
    private static function hasSingleSegmentWildcard(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (str_contains($token, '*') || str_contains($token, '?')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $tokens  Glob split on `**`.
     */
    private static function toLikePattern(array $tokens): string
    {
        $pattern = '';

        foreach ($tokens as $index => $token) {
            if ($index > 0) {
                // The `**` that separated the tokens.
                $pattern .= '%';
            }

            $pattern .= strtr(self::escapeLike($token), ['*' => '%', '?' => '_']);
        }

        return $pattern;
    }

    /**
     * Neutralise LIKE metacharacters that appear literally in the glob.
     * `*` and `?` are deliberately left alone — the caller maps them after.
     */
    private static function escapeLike(string $literal): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $literal);
    }

    private static function literalPrefix(string $glob): string
    {
        $cut = strcspn($glob, '*?');

        return substr($glob, 0, $cut);
    }
}
