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
    // T2.4 — matchesAnyGlob with segment-aware glob semantics (R19 invariant)
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

    /**
     * v8.36 / ADR 0029 — the converter's own output must never be read back
     * as a source: anything under a `{name}.ocr/` segment or the reserved
     * `.artifacts/` root is a generated asset.
     */
    public function test_is_generated_asset_marks_ocr_runs_and_artifact_roots_only(): void
    {
        $this->assertTrue(KbPath::isGeneratedAsset('docs/scan.png.ocr/0123456789abcdef/images/fig-1-1.png'));
        $this->assertTrue(KbPath::isGeneratedAsset('docs/scan.png.ocr/0123456789abcdef/result.json'));
        $this->assertTrue(KbPath::isGeneratedAsset('.artifacts/acme/legal/docs/a.md.versions/x.md'));
        $this->assertTrue(KbPath::isGeneratedAsset('prefix/.artifacts/acme/legal/a.md'));
        $this->assertTrue(KbPath::isGeneratedAsset('Docs/Scan.PNG.OCR/run/images/f.png'));

        $this->assertFalse(KbPath::isGeneratedAsset('docs/scan.png'));
        $this->assertFalse(KbPath::isGeneratedAsset('docs/notes.ocr'), 'a FILE named *.ocr is not a run directory');
        $this->assertFalse(KbPath::isGeneratedAsset('docs/ocr/guide.md'));
        $this->assertFalse(KbPath::isGeneratedAsset('docs/artifacts/guide.md'));
        $this->assertFalse(KbPath::isGeneratedAsset('a/.artifacts'), 'a leaf named .artifacts is a file, not the root');
    }
}
