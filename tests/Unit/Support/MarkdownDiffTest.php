<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MarkdownDiff;
use PHPUnit\Framework\TestCase;

/**
 * v8.7/W5 — line-based markdown diff.
 */
final class MarkdownDiffTest extends TestCase
{
    public function test_identical_text_has_no_changes(): void
    {
        $diff = MarkdownDiff::compute("a\nb\nc", "a\nb\nc");

        $this->assertSame(0, $diff['added']);
        $this->assertSame(0, $diff['removed']);
        $this->assertSame(['context', 'context', 'context'], array_column($diff['rows'], 'type'));
    }

    public function test_detects_added_lines(): void
    {
        $diff = MarkdownDiff::compute("a\nb", "a\nb\nc");

        $this->assertSame(1, $diff['added']);
        $this->assertSame(0, $diff['removed']);
        $added = array_values(array_filter($diff['rows'], fn ($r) => $r['type'] === 'add'));
        $this->assertSame('c', $added[0]['text']);
    }

    public function test_detects_removed_lines(): void
    {
        $diff = MarkdownDiff::compute("a\nb\nc", "a\nc");

        $this->assertSame(0, $diff['added']);
        $this->assertSame(1, $diff['removed']);
        $removed = array_values(array_filter($diff['rows'], fn ($r) => $r['type'] === 'remove'));
        $this->assertSame('b', $removed[0]['text']);
    }

    public function test_detects_a_replaced_line_as_remove_plus_add(): void
    {
        $diff = MarkdownDiff::compute("title\nold body\nfooter", "title\nnew body\nfooter");

        $this->assertSame(1, $diff['added']);
        $this->assertSame(1, $diff['removed']);
    }

    public function test_normalises_line_endings_so_crlf_is_not_a_diff(): void
    {
        $diff = MarkdownDiff::compute("a\r\nb", "a\nb");

        $this->assertSame(0, $diff['added']);
        $this->assertSame(0, $diff['removed']);
    }

    public function test_empty_strings_produce_no_rows(): void
    {
        $diff = MarkdownDiff::compute('', '');

        $this->assertSame([], $diff['rows']);
        $this->assertSame(0, $diff['added']);
    }
}
