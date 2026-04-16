<?php

namespace Tests\Unit\Kb;

use App\Services\Kb\MarkdownChunker;
use Tests\TestCase;

class MarkdownChunkerTest extends TestCase
{
    public function test_splits_markdown_by_blank_lines(): void
    {
        $chunker = new MarkdownChunker();

        $md = "First paragraph.\n\nSecond paragraph.\n\nThird one.";

        $chunks = $chunker->chunk('file.md', $md);

        $this->assertCount(3, $chunks);
        $this->assertSame('First paragraph.', $chunks[0]['text']);
        $this->assertSame('Second paragraph.', $chunks[1]['text']);
        $this->assertSame('Third one.', $chunks[2]['text']);
    }

    public function test_populates_metadata_with_filename_and_order(): void
    {
        $chunker = new MarkdownChunker();

        $chunks = $chunker->chunk('auth.md', "Para A\n\nPara B");

        $this->assertSame('auth.md', $chunks[0]['metadata']['filename']);
        $this->assertSame(0, $chunks[0]['metadata']['order']);
        $this->assertSame(1, $chunks[1]['metadata']['order']);
        $this->assertSame('placeholder_paragraph_split', $chunks[0]['metadata']['strategy']);
    }

    public function test_heading_path_is_null_in_placeholder_implementation(): void
    {
        $chunker = new MarkdownChunker();
        $chunks = $chunker->chunk('f.md', "# Title\n\nBody.");

        $this->assertNull($chunks[0]['heading_path']);
    }

    public function test_filters_empty_blocks(): void
    {
        $chunker = new MarkdownChunker();

        // three blank lines between = still only two content blocks
        $chunks = $chunker->chunk('f.md', "A\n\n\n\nB");

        $this->assertCount(2, $chunks);
    }

    public function test_returns_empty_collection_for_empty_markdown(): void
    {
        $chunker = new MarkdownChunker();

        $this->assertTrue($chunker->chunk('f.md', '')->isEmpty());
        $this->assertTrue($chunker->chunk('f.md', "   \n\n   ")->isEmpty());
    }

    public function test_trims_whitespace_in_chunk_text(): void
    {
        $chunker = new MarkdownChunker();

        $chunks = $chunker->chunk('f.md', "  hello  \n\n  world  ");

        $this->assertSame('hello', $chunks[0]['text']);
        $this->assertSame('world', $chunks[1]['text']);
    }

    public function test_indices_are_re_indexed_after_filtering(): void
    {
        $chunker = new MarkdownChunker();
        // A blank-only block between two valid blocks gets filtered; the
        // resulting collection is ->values() so indices are contiguous.
        $chunks = $chunker->chunk('f.md', "A\n\n   \n\nB");

        $this->assertSame([0, 1], $chunks->keys()->all());
    }
}
