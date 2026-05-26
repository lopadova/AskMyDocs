<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * R19 / H4 — scope_allowlist folder globs must use FNM_PATHNAME so a
 * one-level glob does not match arbitrarily deep paths.
 *
 * Drives the private matchesAnyGlob() via reflection — it is the exact
 * unit under fix, and exercising it directly avoids standing up the full
 * ACL + project-membership stack just to assert a glob-matching nuance.
 */
final class UserScopeGlobTest extends TestCase
{
    private function globMatches(string $path, array $globs): bool
    {
        $method = new ReflectionMethod(User::class, 'matchesAnyGlob');
        $method->setAccessible(true);

        return (bool) $method->invoke(new User, $path, $globs);
    }

    public function test_single_level_glob_does_not_match_deep_path(): void
    {
        // The H4 bug: without FNM_PATHNAME, `*` swallows `/`, so this
        // one-level grant leaked access to nested secrets.
        $this->assertFalse($this->globMatches('hr/policies/deep/nested/secret.md', ['hr/policies/*']));
    }

    public function test_single_level_glob_matches_same_level_file(): void
    {
        $this->assertTrue($this->globMatches('hr/policies/remote-work.md', ['hr/policies/*']));
    }

    public function test_recursive_glob_still_matches_deep_path(): void
    {
        // An explicit per-level glob (`*/*`) matches a 2-level-deep path.
        $this->assertTrue($this->globMatches('hr/policies/deep/secret.md', ['hr/policies/*/*']));
    }

    public function test_double_star_glob_matches_across_segments(): void
    {
        // `**` IS the recursive wildcard (handled by KbPath::matchesAnyGlob,
        // which raw fnmatch(FNM_PATHNAME) could not do). A `docs/**`-style
        // allowlist entry must match arbitrarily deep paths — but stays
        // scoped to its prefix.
        $this->assertTrue($this->globMatches('hr/policies/deep/nested/secret.md', ['hr/policies/**']));
        $this->assertTrue($this->globMatches('hr/policies/x.md', ['hr/**']));
        $this->assertFalse($this->globMatches('finance/secret.md', ['hr/policies/**']));
    }

    public function test_no_globs_never_matches(): void
    {
        $this->assertFalse($this->globMatches('hr/policies/x.md', []));
    }
}
