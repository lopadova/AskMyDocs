<?php

namespace Tests\Unit\Support;

use App\Support\KbPath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class KbPathTest extends TestCase
{
    public function test_collapses_repeated_slashes(): void
    {
        $this->assertSame('docs/auth/oauth.md', KbPath::normalize('docs//auth///oauth.md'));
    }

    public function test_trims_leading_and_trailing_slashes(): void
    {
        $this->assertSame('docs/a.md', KbPath::normalize('/docs/a.md/'));
    }

    public function test_converts_backslashes_to_forward_slashes(): void
    {
        $this->assertSame('docs/auth/oauth.md', KbPath::normalize('docs\\auth\\oauth.md'));
    }

    public function test_passthrough_for_already_normalised_paths(): void
    {
        $this->assertSame('docs/a.md', KbPath::normalize('docs/a.md'));
    }

    public function test_rejects_empty_and_whitespace_normalised_paths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KbPath::normalize('///');
    }

    public function test_rejects_parent_traversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KbPath::normalize('../etc/passwd');
    }

    public function test_rejects_inline_parent_traversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KbPath::normalize('docs/../secrets/passwd');
    }

    public function test_rejects_current_directory_segment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KbPath::normalize('docs/./a.md');
    }

    // ----------------------------------------------------------------------
    // T2.4 — matchesAnyGlob with FNM_PATHNAME (R19 invariant)
    // ----------------------------------------------------------------------

    public function test_matches_any_glob_returns_true_when_a_glob_matches(): void
    {
        $this->assertTrue(KbPath::matchesAnyGlob('hr/policies/leave.md', ['hr/policies/*']));
        $this->assertTrue(KbPath::matchesAnyGlob('docs/api/v2/auth.md', ['docs/api/*/auth.md']));
    }

    public function test_matches_any_glob_returns_false_when_no_glob_matches(): void
    {
        $this->assertFalse(KbPath::matchesAnyGlob('engineering/runbook.md', ['hr/policies/*']));
    }

    public function test_matches_any_glob_returns_false_for_empty_globs_list(): void
    {
        $this->assertFalse(KbPath::matchesAnyGlob('any/path.md', []));
    }

    public function test_matches_any_glob_uses_FNM_PATHNAME_so_star_does_not_cross_segments(): void
    {
        // R19 invariant: `*` must NOT match `/`. Without FNM_PATHNAME,
        // `hr/policies/*` would match `hr/policies/inner/leave.md` —
        // wrong. With FNM_PATHNAME, the match is bounded to a single
        // path segment.
        $this->assertFalse(KbPath::matchesAnyGlob(
            'hr/policies/inner/leave.md',
            ['hr/policies/*'],
        ));
    }

    public function test_matches_any_glob_double_star_crosses_segments(): void
    {
        // `**` is the documented "cross-segments" pattern. With
        // FNM_PATHNAME, ONLY `**` (not `*`) matches across `/`.
        $this->assertTrue(KbPath::matchesAnyGlob(
            'hr/policies/inner/leave.md',
            ['hr/policies/**'],
        ));
    }

    public function test_matches_any_glob_short_circuits_on_first_match(): void
    {
        // No deterministic way to assert short-circuit ordering from
        // outside, but verify that adding more globs after a hit still
        // returns true (and conversely a non-hit followed by a hit
        // also returns true).
        $this->assertTrue(KbPath::matchesAnyGlob('a/b.md', ['x/y/*', 'a/*']));
        $this->assertTrue(KbPath::matchesAnyGlob('a/b.md', ['a/*', 'x/y/*']));
    }
}
