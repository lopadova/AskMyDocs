<?php

namespace Tests\Unit\Kb;

use App\Services\Kb\MarkdownChunker;
use Tests\TestCase;

class MarkdownChunkerTest extends TestCase
{
    private MarkdownChunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chunker = new MarkdownChunker();
    }

    // -------------------------------------------------------------
    // Legacy backward-compat — paragraph fallback when no headings
    // -------------------------------------------------------------

    public function test_splits_markdown_by_blank_lines_when_no_headings(): void
    {
        $md = "First paragraph.\n\nSecond paragraph.\n\nThird one.";
        $chunks = $this->chunker->chunk('file.md', $md);

        $this->assertCount(3, $chunks);
        $this->assertSame('First paragraph.', $chunks[0]['text']);
        $this->assertSame('Second paragraph.', $chunks[1]['text']);
        $this->assertSame('Third one.', $chunks[2]['text']);
        $this->assertSame('paragraph_split', $chunks[0]['metadata']['strategy']);
    }

    public function test_populates_metadata_with_filename_and_order(): void
    {
        $chunks = $this->chunker->chunk('auth.md', "Para A\n\nPara B");

        $this->assertSame('auth.md', $chunks[0]['metadata']['filename']);
        $this->assertSame(0, $chunks[0]['metadata']['order']);
        $this->assertSame(1, $chunks[1]['metadata']['order']);
    }

    public function test_filters_empty_blocks(): void
    {
        $chunks = $this->chunker->chunk('f.md', "A\n\n\n\nB");
        $this->assertCount(2, $chunks);
    }

    public function test_returns_empty_collection_for_empty_markdown(): void
    {
        $this->assertTrue($this->chunker->chunk('f.md', '')->isEmpty());
        $this->assertTrue($this->chunker->chunk('f.md', "   \n\n   ")->isEmpty());
    }

    public function test_trims_whitespace_in_chunk_text(): void
    {
        $chunks = $this->chunker->chunk('f.md', "  hello  \n\n  world  ");
        $this->assertSame('hello', $chunks[0]['text']);
        $this->assertSame('world', $chunks[1]['text']);
    }

    public function test_indices_are_re_indexed_after_filtering(): void
    {
        $chunks = $this->chunker->chunk('f.md', "A\n\n   \n\nB");
        $this->assertSame([0, 1], $chunks->keys()->all());
    }

    // -------------------------------------------------------------
    // v2 — section-aware chunking
    // -------------------------------------------------------------

    public function test_splits_on_h1_headings(): void
    {
        $md = "# Section A\n\nBody of A.\n\n# Section B\n\nBody of B.";
        $chunks = $this->chunker->chunk('f.md', $md);

        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('Body of A', $chunks[0]['text']);
        $this->assertStringContainsString('Body of B', $chunks[1]['text']);
        $this->assertSame('section_aware', $chunks[0]['metadata']['strategy']);
    }

    public function test_splits_on_h2_headings(): void
    {
        $md = "# Root\n\nIntro.\n\n## Alpha\n\nAlpha body.\n\n## Beta\n\nBeta body.";
        $chunks = $this->chunker->chunk('f.md', $md);

        $this->assertCount(3, $chunks);
        $this->assertSame('Root', $chunks[0]['heading_path']);
        $this->assertSame('Root > Alpha', $chunks[1]['heading_path']);
        $this->assertSame('Root > Beta', $chunks[2]['heading_path']);
    }

    public function test_preserves_heading_path_breadcrumb_across_h1_h2_h3(): void
    {
        $md = "# Top\n\nTop body.\n\n## Mid\n\nMid body.\n\n### Leaf\n\nLeaf body.";
        $chunks = $this->chunker->chunk('f.md', $md);

        $paths = $chunks->pluck('heading_path')->all();
        $this->assertSame(['Top', 'Top > Mid', 'Top > Mid > Leaf'], $paths);
    }

    public function test_h2_resets_lower_levels_when_a_new_h1_starts(): void
    {
        // Each heading gets its own body paragraph so the section emits a
        // chunk. Sections without any body are intentionally dropped —
        // heading-only sections are navigational, not content.
        $md = "# A\n\nTop of A.\n\n## A1\n\nA1 body.\n\n### A1a\n\nA1a body.\n\n# B\n\nB body.";
        $chunks = $this->chunker->chunk('f.md', $md);

        $paths = $chunks->pluck('heading_path')->all();
        $this->assertSame(['A', 'A > A1', 'A > A1 > A1a', 'B'], $paths);
    }

    public function test_content_before_first_heading_is_emitted_with_empty_heading_path(): void
    {
        $md = "Orphan intro with no heading.\n\n# Section\n\nIn section.";
        $chunks = $this->chunker->chunk('f.md', $md);

        $this->assertCount(2, $chunks);
        $this->assertSame('', $chunks[0]['heading_path']);
        $this->assertSame('Section', $chunks[1]['heading_path']);
    }

    // -------------------------------------------------------------
    // v2 — frontmatter stripping
    // -------------------------------------------------------------

    public function test_strips_frontmatter_block_before_chunking(): void
    {
        $md = "---\nslug: x\ntype: decision\n---\n\n# Decision\n\nBody.";
        $chunks = $this->chunker->chunk('f.md', $md);

        foreach ($chunks as $chunk) {
            $this->assertStringNotContainsString('slug: x', $chunk['text']);
            $this->assertStringNotContainsString('---', $chunk['text']);
        }
    }

    public function test_frontmatter_without_closing_fence_is_treated_as_body(): void
    {
        // No closing ---, so parser treats it as raw text (caller's responsibility
        // to have structurally valid frontmatter).
        $md = "---\nslug: x\ntype: decision\n\n# Header\n\nBody.";
        $chunks = $this->chunker->chunk('f.md', $md);
        $this->assertNotEmpty($chunks);
    }

    // -------------------------------------------------------------
    // v2 — wikilink extraction into metadata
    // -------------------------------------------------------------

    public function test_attaches_wikilinks_to_chunk_metadata(): void
    {
        $md = "# Decision\n\nSee [[module-cache]] and [[dec-previous]] for context.";
        $chunks = $this->chunker->chunk('f.md', $md);

        $this->assertContains('module-cache', $chunks[0]['metadata']['wikilinks']);
        $this->assertContains('dec-previous', $chunks[0]['metadata']['wikilinks']);
    }

    public function test_wikilinks_is_empty_list_when_no_links_present(): void
    {
        $chunks = $this->chunker->chunk('f.md', "# A\n\nJust prose.");
        $this->assertSame([], $chunks[0]['metadata']['wikilinks']);
    }

    // -------------------------------------------------------------
    // v2 — fence-aware heading detection (Copilot review PR #9)
    // -------------------------------------------------------------

    public function test_hash_lines_inside_fenced_code_block_are_not_treated_as_headings(): void
    {
        // No ATX heading outside the fence → must fall back to paragraph_split.
        $md = "Intro text.\n\n```bash\n# this is a shell comment, NOT a heading\necho hi\n```\n\nMore prose.";
        $chunks = $this->chunker->chunk('f.md', $md);

        $this->assertSame('paragraph_split', $chunks[0]['metadata']['strategy']);
        foreach ($chunks as $chunk) {
            $this->assertNull($chunk['heading_path']);
        }
    }

    public function test_real_heading_wins_even_when_fence_contains_hash_lines(): void
    {
        $md = "# Real H1\n\nProse.\n\n```\n# comment inside fence\n```\n\nMore prose.";
        $chunks = $this->chunker->chunk('f.md', $md);

        $this->assertSame('section_aware', $chunks[0]['metadata']['strategy']);
        // All chunks belong to the single real H1 — the fence content must
        // stay inside the same section, never start a new one.
        foreach ($chunks as $chunk) {
            $this->assertSame('Real H1', $chunk['heading_path']);
        }
    }

    public function test_tilde_fence_is_also_respected(): void
    {
        $md = "Intro.\n\n~~~\n# not a heading\n~~~\n\nOutro.";
        $chunks = $this->chunker->chunk('f.md', $md);
        $this->assertSame('paragraph_split', $chunks[0]['metadata']['strategy']);
    }

    public function test_wikilinks_in_code_blocks_are_ignored(): void
    {
        $md = "# A\n\nProse [[keep]].\n\n```\n[[ignore]]\n```\n\nMore [[keep2]].";
        $chunks = $this->chunker->chunk('f.md', $md);
        // first chunk contains both real links
        $allLinks = collect($chunks)->flatMap(fn ($c) => $c['metadata']['wikilinks'])->all();
        $this->assertContains('keep', $allLinks);
        $this->assertContains('keep2', $allLinks);
        $this->assertNotContains('ignore', $allLinks);
    }

    // -------------------------------------------------------------
    // v2 — hard-cap splitting within a section
    // -------------------------------------------------------------

    public function test_respects_hard_cap_tokens_on_large_section(): void
    {
        // config('kb.chunking.hard_cap_tokens', 1024) by default → ~4096 chars.
        // Build 3 paragraphs each ~1800 chars so total > 5000 chars > 1250 tokens.
        $para = str_repeat('word ', 360);
        $md = "# Big\n\n" . $para . "\n\n" . $para . "\n\n" . $para;

        $chunks = $this->chunker->chunk('f.md', $md);

        // Should produce more than 1 chunk (section was oversized).
        $this->assertGreaterThan(1, $chunks->count());
        foreach ($chunks as $chunk) {
            $this->assertSame('Big', $chunk['heading_path']);
        }
    }

    // -------------------------------------------------------------
    // v2 — filename + order preserved under section_aware strategy
    // -------------------------------------------------------------

    public function test_metadata_preserved_across_section_aware_mode(): void
    {
        $chunks = $this->chunker->chunk('runbook.md', "# S\n\nBody");
        $this->assertSame('runbook.md', $chunks[0]['metadata']['filename']);
        $this->assertSame('section_aware', $chunks[0]['metadata']['strategy']);
        $this->assertSame(0, $chunks[0]['metadata']['order']);
    }
}
