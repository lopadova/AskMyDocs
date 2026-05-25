<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LikeEscaper;
use PHPUnit\Framework\TestCase;

/**
 * R19 — LikeEscaper completeness guard.
 *
 * The historical bug (H5): code escaped `%` but left `_` and `\` raw, so
 * a search for `a_b` matched `acb`. These assertions pin all three
 * meta-characters AND the escape-char-first ordering.
 */
final class LikeEscaperTest extends TestCase
{
    public function test_escapes_percent(): void
    {
        $this->assertSame('100\\% off', LikeEscaper::escape('100% off'));
    }

    public function test_escapes_underscore(): void
    {
        // The H5 regression: `_` is a single-char wildcard and MUST escape.
        $this->assertSame('a\\_b', LikeEscaper::escape('a_b'));
    }

    public function test_escapes_backslash_first_so_it_is_not_double_counted(): void
    {
        // A literal backslash becomes two; a following `%` gets exactly one
        // escaping backslash (not three).
        $this->assertSame('\\\\\\%', LikeEscaper::escape('\\%'));
    }

    public function test_leaves_ordinary_text_untouched(): void
    {
        $this->assertSame('plain text 123', LikeEscaper::escape('plain text 123'));
    }

    public function test_contains_wraps_escaped_term(): void
    {
        $this->assertSame('%a\\_b%', LikeEscaper::contains('a_b'));
    }
}
