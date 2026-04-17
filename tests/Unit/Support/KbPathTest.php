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
}
