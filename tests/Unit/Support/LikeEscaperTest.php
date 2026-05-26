<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LikeEscaper;
use PHPUnit\Framework\TestCase;

/**
 * R19 — LikeEscaper completeness guard.
 *
 * The historical bug (H5): code escaped `%` but left `_` raw, so a search
 * for `a_b` matched `acb`. These assertions pin all meta-characters and the
 * escape-char-first ordering.
 *
 * The escape character is `~`, NOT backslash — a backslash ESCAPE clause
 * crashes on Postgres+PDO with SQLSTATE[HY093] (see LikeEscaper docblock).
 */
final class LikeEscaperTest extends TestCase
{
    public function test_escape_char_is_not_backslash(): void
    {
        // Guard the PDO-safety invariant: the escape char MUST NOT be a
        // backslash, and the SQL clause MUST NOT contain one.
        $this->assertSame('~', LikeEscaper::ESCAPE_CHAR);
        $this->assertStringNotContainsString('\\', LikeEscaper::ESCAPE_SQL);
        $this->assertSame("ESCAPE '~'", LikeEscaper::ESCAPE_SQL);
    }

    public function test_escapes_percent(): void
    {
        $this->assertSame('100~% off', LikeEscaper::escape('100% off'));
    }

    public function test_escapes_underscore(): void
    {
        // The H5 regression: `_` is a single-char wildcard and MUST escape.
        $this->assertSame('a~_b', LikeEscaper::escape('a_b'));
    }

    public function test_escapes_the_escape_char_first(): void
    {
        // A literal `~` becomes `~~`; a following `%` gets exactly one
        // escape prefix (not three) — escape-char-first ordering.
        $this->assertSame('~~~%', LikeEscaper::escape('~%'));
    }

    public function test_leaves_ordinary_text_and_backslash_untouched(): void
    {
        // Backslash is no longer special — it passes through verbatim.
        $this->assertSame('plain\\text 123', LikeEscaper::escape('plain\\text 123'));
    }

    public function test_contains_wraps_escaped_term(): void
    {
        $this->assertSame('%a~_b%', LikeEscaper::contains('a_b'));
    }
}
