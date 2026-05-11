<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Chunking;

use App\Services\Kb\Chunkers\AtomicNoteChunker;
use App\Services\Kb\Pipeline\ConvertedDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtomicNoteChunkerTest extends TestCase
{
    #[Test]
    public function supports_atomic_note_source_types(): void
    {
        $chunker = new AtomicNoteChunker();
        $this->assertTrue($chunker->supports('evernote'));
        $this->assertTrue($chunker->supports('fabric'));
        $this->assertTrue($chunker->supports('notion_note'));
        $this->assertFalse($chunker->supports('notion'));
        $this->assertFalse($chunker->supports('markdown'));
    }

    #[Test]
    public function short_evernote_note_becomes_a_single_chunk(): void
    {
        $chunker = new AtomicNoteChunker();
        $doc = new ConvertedDocument(
            markdown: "# Quick note\n\nThis is a short Evernote capture.",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'quick.evernote.md',
                'source_type' => 'evernote',
                'evernote' => ['notebook' => 'inbox'],
                '_derived' => [
                    'search_tags' => ['inbox', 'capture'],
                    'status_active' => true,
                    'recency_bucket' => 'this_week',
                ],
            ],
            sourceMimeType: 'application/vnd.evernote.note+xml',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertCount(1, $drafts);
        $this->assertSame('Quick note', $drafts[0]->headingPath);
        $this->assertSame('inbox', $drafts[0]->metadata['notebook']);
        $this->assertSame(['inbox', 'capture'], $drafts[0]->metadata['search_tags']);
        $this->assertSame('atomic-note', $drafts[0]->metadata['strategy']);
    }

    #[Test]
    public function long_note_splits_on_h2_sections(): void
    {
        $chunker = new AtomicNoteChunker();
        // Body big enough to exceed the 800-token atomic budget — ~4kb of paragraphs.
        $bigBody = str_repeat("Lorem ipsum dolor sit amet, consectetur adipiscing elit. " .
            "Praesent tellus libero, hendrerit nec rutrum at, sodales eu nibh. ", 80);
        $markdown = "## First section\n\n{$bigBody}\n\n## Second section\n\n{$bigBody}\n";

        $doc = new ConvertedDocument(
            markdown: $markdown,
            mediaItems: [],
            extractionMeta: [
                'filename' => 'long.fabric.md',
                'source_type' => 'fabric',
                'fabric' => ['collection_id' => 'col-123'],
                '_derived' => ['search_tags' => ['research']],
            ],
            sourceMimeType: 'application/vnd.fabric.note+json',
        );

        $drafts = $chunker->chunk($doc);
        $this->assertGreaterThanOrEqual(2, count($drafts));
        $headings = array_map(static fn ($d) => $d->headingPath, $drafts);
        $this->assertContains('First section', $headings);
        $this->assertContains('Second section', $headings);
        foreach ($drafts as $d) {
            $this->assertSame('col-123', $d->metadata['collection_id']);
            $this->assertSame('fabric', $d->metadata['source_type']);
        }
    }

    #[Test]
    public function empty_body_emits_no_chunks(): void
    {
        $chunker = new AtomicNoteChunker();
        $doc = new ConvertedDocument(
            markdown: "---\nfoo: bar\n---\n",
            mediaItems: [],
            extractionMeta: [
                'filename' => 'empty.evernote.md',
                'source_type' => 'evernote',
                '_derived' => ['search_tags' => []],
            ],
            sourceMimeType: 'application/vnd.evernote.note+xml',
        );

        $this->assertSame([], $chunker->chunk($doc));
    }
}
