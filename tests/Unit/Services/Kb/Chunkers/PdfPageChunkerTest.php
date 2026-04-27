<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Chunkers;

use App\Services\Kb\Chunkers\PdfPageChunker;
use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PdfPageChunkerTest extends TestCase
{
    public function test_implements_chunker_interface(): void
    {
        $this->assertInstanceOf(ChunkerInterface::class, new PdfPageChunker());
    }

    public function test_name_is_stable_kebab_identifier(): void
    {
        $this->assertSame('pdf-page-chunker', (new PdfPageChunker())->name());
    }

    #[DataProvider('sourceTypeProvider')]
    public function test_supports_pdf_source_type_only(string $sourceType, bool $expected): void
    {
        $this->assertSame($expected, (new PdfPageChunker())->supports($sourceType));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function sourceTypeProvider(): iterable
    {
        yield 'pdf'                 => ['pdf', true];
        yield 'markdown rejected'   => ['markdown', false];
        yield 'md rejected'         => ['md', false];
        yield 'text rejected'       => ['text', false];
        yield 'docx rejected'       => ['docx', false];
        yield 'unknown rejected'    => ['unknown', false];
        yield 'empty rejected'      => ['', false];
    }

    public function test_chunks_pdf_one_chunk_per_page_with_heading_path(): void
    {
        $cd = new ConvertedDocument(
            markdown: "# doc.pdf\n\n## Page 1\n\nP1 body.\n\n## Page 2\n\nP2 body.\n\n## Page 3\n\nP3 body.\n",
            mediaItems: [],
            extractionMeta: ['converter' => 'pdf-converter', 'page_count' => 3, 'filename' => 'doc.pdf'],
            sourceMimeType: 'application/pdf',
        );

        $chunks = (new PdfPageChunker())->chunk($cd);

        $this->assertCount(3, $chunks);
        $this->assertContainsOnlyInstancesOf(ChunkDraft::class, $chunks);
        $this->assertSame('Page 1', $chunks[0]->headingPath);
        $this->assertSame('Page 2', $chunks[1]->headingPath);
        $this->assertSame('Page 3', $chunks[2]->headingPath);
        $this->assertStringContainsString('P1 body', $chunks[0]->text);
        $this->assertStringContainsString('P2 body', $chunks[1]->text);
        $this->assertStringContainsString('P3 body', $chunks[2]->text);
        $this->assertSame(0, $chunks[0]->order);
        $this->assertSame(1, $chunks[1]->order);
        $this->assertSame(2, $chunks[2]->order);
    }

    public function test_metadata_includes_filename_strategy_and_page_number(): void
    {
        $cd = new ConvertedDocument(
            markdown: "# rep.pdf\n\n## Page 1\n\nbody.\n\n## Page 2\n\nmore body.",
            mediaItems: [],
            extractionMeta: ['filename' => 'rep.pdf'],
            sourceMimeType: 'application/pdf',
        );

        $chunks = (new PdfPageChunker())->chunk($cd);

        $this->assertSame('rep.pdf', $chunks[0]->metadata['filename']);
        $this->assertSame('pdf-page', $chunks[0]->metadata['strategy']);
        $this->assertSame(1, $chunks[0]->metadata['page']);
        $this->assertSame(2, $chunks[1]->metadata['page']);
    }

    public function test_falls_back_to_unknown_pdf_when_filename_meta_missing(): void
    {
        $cd = new ConvertedDocument(
            markdown: "## Page 1\n\nbody.",
            mediaItems: [],
            extractionMeta: [],
            sourceMimeType: 'application/pdf',
        );

        $chunks = (new PdfPageChunker())->chunk($cd);

        $this->assertCount(1, $chunks);
        $this->assertSame('unknown.pdf', $chunks[0]->metadata['filename']);
    }

    public function test_returns_empty_array_for_markdown_with_no_page_headings(): void
    {
        // PdfConverter on a scanned/image-only PDF returns empty markdown,
        // but a defensive case: if the markdown has no `## Page N` headings
        // at all (unusual — would indicate a misconfigured pipeline), the
        // chunker emits nothing rather than treating the whole body as
        // one heading-less chunk.
        $cd = new ConvertedDocument(
            markdown: "# doc.pdf\n\nBody with no page headings.",
            mediaItems: [],
            extractionMeta: ['filename' => 'doc.pdf'],
            sourceMimeType: 'application/pdf',
        );

        $this->assertSame([], (new PdfPageChunker())->chunk($cd));
    }

    public function test_returns_empty_array_for_empty_markdown(): void
    {
        $cd = new ConvertedDocument(
            markdown: '',
            mediaItems: [],
            extractionMeta: ['filename' => 'doc.pdf'],
            sourceMimeType: 'application/pdf',
        );

        $this->assertSame([], (new PdfPageChunker())->chunk($cd));
    }

    public function test_skips_pages_with_empty_body(): void
    {
        $cd = new ConvertedDocument(
            markdown: "# doc.pdf\n\n## Page 1\n\n\n\n## Page 2\n\nReal body.\n\n## Page 3\n\n",
            mediaItems: [],
            extractionMeta: ['filename' => 'doc.pdf'],
            sourceMimeType: 'application/pdf',
        );

        $chunks = (new PdfPageChunker())->chunk($cd);

        // Only Page 2 has real text; Pages 1 and 3 are dropped so we never
        // emit heading-only "Page N" chunks (vector-index pollution guard).
        $this->assertCount(1, $chunks);
        $this->assertSame('Page 2', $chunks[0]->headingPath);
        $this->assertStringContainsString('Real body', $chunks[0]->text);
    }

    public function test_splits_oversized_page_into_multiple_chunks_with_same_heading_path(): void
    {
        // Tune the cap below for predictable splitting: each para below is
        // roughly 200 chars, so with cap = 80 tokens (~320 chars) the page
        // must split into multiple pieces.
        config()->set('kb.chunking.hard_cap_tokens', 80);

        $hugeBody = str_repeat("This is a paragraph of body text that takes some space in the page. ", 5);
        $hugeBody .= "\n\n";
        $hugeBody .= str_repeat("Another paragraph that also takes meaningful space and forces a split. ", 5);
        $hugeBody .= "\n\n";
        $hugeBody .= str_repeat("Yet another paragraph that should land in its own piece after splitting. ", 5);

        $cd = new ConvertedDocument(
            markdown: "# big.pdf\n\n## Page 1\n\n{$hugeBody}",
            mediaItems: [],
            extractionMeta: ['filename' => 'big.pdf'],
            sourceMimeType: 'application/pdf',
        );

        $chunks = (new PdfPageChunker())->chunk($cd);

        $this->assertGreaterThan(1, count($chunks), 'expect multiple chunks for an oversized page');
        // ALL pieces of the same page share the same heading_path so
        // citations still resolve to the page level.
        foreach ($chunks as $chunk) {
            $this->assertSame('Page 1', $chunk->headingPath);
            $this->assertSame(1, $chunk->metadata['page']);
        }
    }
}
