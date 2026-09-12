<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Canonical normalisation for KB source paths.
 *
 * Shared between the ingest and delete entry points (HTTP controllers +
 * artisan commands) so the two flows never diverge on what constitutes a
 * valid path. A document ingested as `docs//foo.md` is normalised to
 * `docs/foo.md` at write time — any later delete call must apply the same
 * rules or it would emit spurious "not found" errors.
 */
class KbPath
{
    /**
     * @throws InvalidArgumentException when the path is empty after
     *         normalisation or contains "." / ".." segments.
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = trim($path, '/');

        if ($path === '') {
            throw new InvalidArgumentException('Source path must be a non-empty relative path.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Source path must be a relative path without "." or ".." segments.');
            }
        }

        return $path;
    }

    /**
     * Returns true when `$path` matches ANY of the provided globs.
     *
     * Per R19 (input-escape-complete), path-pattern matching requires
     * segment-aware semantics:
     *  - `*`  matches any character EXCEPT `/` (one path segment, partial)
     *  - `**` matches any character INCLUDING `/` (cross-segment recursion)
     *  - `?`  matches a single character EXCEPT `/`
     *  - `.`  is literal (escaped in the regex translation)
     *
     * PHP's native `fnmatch($pattern, $path, FNM_PATHNAME)` enforces the
     * `*`-doesn't-cross-`/` invariant but does NOT recognise `**` as a
     * cross-segment wildcard — both stars get the same treatment.
     * Without `**` support, the documented "hr/policies/**" pattern
     * would NOT match `hr/policies/inner/leave.md`. We translate the
     * glob to a PCRE regex so `**` can mean "cross segments" and the
     * other invariants stay intact.
     *
     * Used by {@see \App\Services\Kb\KbSearchService::search()} for the
     * post-fetch folder-glob filter (T2.4) — applied AFTER the SQL
     * candidate fetch because PostgreSQL has no native fnmatch and
     * `**` globs don't map cleanly to LIKE.
     *
     * Returns FALSE for an empty `$globs` array (no globs to match
     * against → no rows match; callers should short-circuit this case
     * to apply the filter only when globs are non-empty).
     *
     * @param  list<string>  $globs
     */
    /**
     * True when a path lies inside a generated-asset subtree the ingestion
     * pipeline writes itself and must never read back as a source:
     * `{source}.ocr/…` (OCR runs + figures, v8.36 / ADR 0029) and the
     * `.artifacts/` root reserved for conversion artifacts (ADR 0030).
     * Discovery (folder walker, orphan sweeps) and the HTTP ingest entry
     * point all consult it, so a recursive ingest cannot self-ingest the
     * figures it extracted on the previous run.
     */
    public static function isGeneratedAsset(string $path): bool
    {
        $segments = explode('/', str_replace('\\', '/', trim($path, '/')));
        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }
            if (str_ends_with(strtolower($segment), '.ocr') && $index < count($segments) - 1) {
                return true;
            }
            if ($segment === '.artifacts' && $index < count($segments) - 1) {
                return true;
            }
        }

        return false;
    }

    public static function matchesAnyGlob(string $path, array $globs): bool
    {
        foreach ($globs as $glob) {
            $regex = self::globToRegex($glob);
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Translate a path glob to an anchored PCRE regex.
     *
     * Steps:
     *  1. Tokenize on `**` so cross-segment wildcards survive escape.
     *  2. For each non-`**` token, replace `*` and `?` with sentinels
     *     before quoting (preg_quote escapes them as literals).
     *  3. Restore the sentinels to `[^/]*` (single-segment any) and
     *     `[^/]` (single-segment one-char) AFTER the quote.
     *  4. Re-join tokens with `.*` for each `**`.
     *  5. Anchor with `^` / `$` so partial matches don't leak.
     */
    private static function globToRegex(string $glob): string
    {
        $tokens = explode('**', $glob);
        $parts = array_map(static function (string $token): string {
            $token = strtr($token, [
                '*' => "\x01",
                '?' => "\x02",
            ]);
            $token = preg_quote($token, '#');
            return strtr($token, [
                "\x01" => '[^/]*',
                "\x02" => '[^/]',
            ]);
        }, $tokens);

        return '#^' . implode('.*', $parts) . '$#';
    }
}
