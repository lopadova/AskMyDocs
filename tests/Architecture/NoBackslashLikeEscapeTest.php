<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * R19 — forbid backslash LIKE `ESCAPE` clauses across app/.
 *
 * A backslash escape clause (`ESCAPE '\'`) silently works on SQLite (so
 * PHPUnit never catches it) but crashes on PostgreSQL via PDO with
 * `SQLSTATE[HY093]: Invalid parameter number`: the emulated-prepare parser
 * reads the backslash-before-closing-quote as an escaped quote and swallows
 * the next `?` placeholder. Since the E2E job runs on Postgres and the unit
 * suite on SQLite, this class is the only PHPUnit-level guard. Every LIKE
 * site must use `App\Support\LikeEscaper::ESCAPE_SQL` (escape char `~`).
 *
 * Caught in v8.0.3: `KbDocumentSearchController` + `UserController` searches
 * 500'd on the Postgres E2E job after the R19 escaping was added with a
 * backslash escape char.
 */
final class NoBackslashLikeEscapeTest extends TestCase
{
    public function test_no_backslash_escape_clause_in_app(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        // Match `ESCAPE '\'` in source as written in a PHP double-quoted
        // string ("... ESCAPE '\\'") OR a single-quoted one ("... ESCAPE '\'").
        $needles = ["ESCAPE '\\\\'", "ESCAPE '\\'"];

        $offenders = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $entry) {
            if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($entry->getPathname());
            foreach ($needles as $needle) {
                if (str_contains($code, $needle)) {
                    $base = str_replace('\\', '/', dirname(__DIR__, 2)).'/';
                    $offenders[] = str_replace($base, '', str_replace('\\', '/', $entry->getPathname()));
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Backslash LIKE ESCAPE clause found (Postgres+PDO HY093 risk). Use "
            ."App\\Support\\LikeEscaper::ESCAPE_SQL (escape char '~') instead:\n  - "
            .implode("\n  - ", $offenders),
        );
    }
}
