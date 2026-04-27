<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Converters\PdfConverter;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Pdf\PdfFixtureBuilder;

final class PdfConverterTest extends TestCase
{
    public function test_implements_converter_interface(): void
    {
        $this->assertInstanceOf(ConverterInterface::class, new PdfConverter());
    }

    public function test_name_is_stable_kebab_identifier(): void
    {
        $this->assertSame('pdf-converter', (new PdfConverter())->name());
    }

    #[DataProvider('mimeProvider')]
    public function test_supports_pdf_mime_only(string $mime, bool $expected): void
    {
        $this->assertSame($expected, (new PdfConverter())->supports($mime));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function mimeProvider(): iterable
    {
        yield 'application/pdf'         => ['application/pdf', true];
        yield 'text/plain rejected'     => ['text/plain', false];
        yield 'text/markdown rejected'  => ['text/markdown', false];
        yield 'application/octet-stream rejected' => ['application/octet-stream', false];
        yield 'empty mime rejected'     => ['', false];
    }

    public function test_extracts_text_from_three_page_pdf_with_page_markers(): void
    {
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        $result = (new PdfConverter())->convert($this->sourceDoc($bytes, 'docs/sample.pdf'));

        $this->assertInstanceOf(ConvertedDocument::class, $result);
        $this->assertStringContainsString('# sample.pdf', $result->markdown);
        $this->assertStringContainsString('## Page 1', $result->markdown);
        $this->assertStringContainsString('## Page 2', $result->markdown);
        $this->assertStringContainsString('## Page 3', $result->markdown);
        $this->assertStringContainsString('Lorem ipsum about A.', $result->markdown);
        $this->assertStringContainsString('Lorem ipsum about B.', $result->markdown);
        $this->assertStringContainsString('Lorem ipsum about C.', $result->markdown);
    }

    public function test_extraction_meta_includes_page_count_strategy_and_filename(): void
    {
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        $result = (new PdfConverter())->convert($this->sourceDoc($bytes, 'reports/q1.pdf'));

        $this->assertSame('pdf-converter', $result->extractionMeta['converter']);
        $this->assertSame(3, $result->extractionMeta['page_count']);
        $this->assertSame('smalot', $result->extractionMeta['extraction_strategy']);
        $this->assertSame('reports/q1.pdf', $result->extractionMeta['source_path']);
        $this->assertSame('q1.pdf', $result->extractionMeta['filename']);
        $this->assertArrayHasKey('duration_ms', $result->extractionMeta);
        $this->assertGreaterThanOrEqual(0, $result->extractionMeta['duration_ms']);
        $this->assertSame('application/pdf', $result->sourceMimeType);
        $this->assertSame([], $result->mediaItems);
    }

    public function test_single_page_pdf_yields_one_page_in_meta(): void
    {
        $bytes = PdfFixtureBuilder::buildSinglePage('Just a single page body.');
        $result = (new PdfConverter())->convert($this->sourceDoc($bytes, 'docs/short.pdf'));

        $this->assertSame(1, $result->extractionMeta['page_count']);
        $this->assertStringContainsString('Just a single page body.', $result->markdown);
    }

    public function test_skips_pages_that_extract_to_empty_text(): void
    {
        // PdfFixtureBuilder requires at least one page, but individual page
        // strings may still be empty or whitespace-only. Pages with only
        // whitespace-equivalent content (e.g. a single space — smalot will
        // recover something but cleanText() strips it to '') should not
        // produce heading-only chunks. We verify the behaviour by passing a
        // real-text page alongside a single-space page and asserting only
        // the real-text page survives.
        $bytes = PdfFixtureBuilder::build([' ', 'Real content on second page.']);
        $result = (new PdfConverter())->convert($this->sourceDoc($bytes, 'docs/sparse.pdf'));

        // Only Page 2 has real text; Page 1's whitespace-only body is skipped.
        $this->assertStringNotContainsString('## Page 1', $result->markdown);
        $this->assertStringContainsString('## Page 2', $result->markdown);
        $this->assertStringContainsString('Real content on second page.', $result->markdown);
    }

    public function test_returns_empty_markdown_when_every_page_is_empty(): void
    {
        // All-whitespace pages → empty markdown → MarkdownChunker returns [].
        // This avoids producing meaningless chunks from scanned/image-only
        // PDFs with no extractable text. (Whether an empty document row is
        // still persisted by DocumentIngestor is a separate pipeline concern
        // not asserted here.)
        $bytes = PdfFixtureBuilder::build([' ', "\t", '   ']);
        $result = (new PdfConverter())->convert($this->sourceDoc($bytes, 'docs/scan-only.pdf'));

        $this->assertSame('', $result->markdown);
        // Filename meta is still set so admin observability can attribute
        // the empty source to its path.
        $this->assertSame('scan-only.pdf', $result->extractionMeta['filename']);
    }

    public function test_throws_runtime_exception_when_both_strategies_fail(): void
    {
        // A non-PDF byte string fails smalot AND pdftotext (the latter only if
        // the binary is present; otherwise its absence is itself the failure
        // path). Either way, we expect a RuntimeException naming the source.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PdfConverter could not extract text from "docs\/garbage.pdf"/');

        (new PdfConverter())->convert($this->sourceDoc('not a pdf at all', 'docs/garbage.pdf'));
    }

    private function sourceDoc(string $bytes, string $sourcePath): SourceDocument
    {
        return new SourceDocument(
            sourcePath: $sourcePath,
            mimeType: 'application/pdf',
            bytes: $bytes,
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );
    }
}
