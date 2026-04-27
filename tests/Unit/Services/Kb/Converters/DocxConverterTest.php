<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Converters\DocxConverter;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Docx\DocxFixtureBuilder;

final class DocxConverterTest extends TestCase
{
    public function test_implements_converter_interface(): void
    {
        $this->assertInstanceOf(ConverterInterface::class, new DocxConverter());
    }

    public function test_name_is_stable_kebab_identifier(): void
    {
        $this->assertSame('docx-converter', (new DocxConverter())->name());
    }

    #[DataProvider('mimeProvider')]
    public function test_supports_docx_mime_only(string $mime, bool $expected): void
    {
        $this->assertSame($expected, (new DocxConverter())->supports($mime));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function mimeProvider(): iterable
    {
        yield 'docx mime type' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            true,
        ];
        yield 'pdf rejected'        => ['application/pdf', false];
        yield 'text/markdown rejected' => ['text/markdown', false];
        yield 'text/plain rejected' => ['text/plain', false];
        yield 'old .doc rejected'   => ['application/msword', false];
        yield 'empty mime rejected' => ['', false];
    }

    public function test_extracts_docx_with_headings_and_body(): void
    {
        $bytes = DocxFixtureBuilder::buildHeadingsSample();
        $result = (new DocxConverter())->convert($this->sourceDoc($bytes, 'docs/spec.docx'));

        $this->assertInstanceOf(ConvertedDocument::class, $result);
        // Document-level H1 = basename so MarkdownChunker has a stable
        // breadcrumb anchor (per T1.5 LESSONS handshake).
        $this->assertStringStartsWith("# spec.docx\n\n", $result->markdown);
        // Heading 1 in the source becomes H2 (basename owns H1).
        $this->assertStringContainsString('## Introduction', $result->markdown);
        // Heading 2 becomes H3.
        $this->assertStringContainsString('### Background', $result->markdown);
        // Body paragraphs are preserved as plain prose.
        $this->assertStringContainsString('intro paragraph body', $result->markdown);
        $this->assertStringContainsString('background paragraph body', $result->markdown);
    }

    public function test_extraction_meta_includes_source_path_and_filename(): void
    {
        $bytes = DocxFixtureBuilder::buildHeadingsSample();
        $result = (new DocxConverter())->convert($this->sourceDoc($bytes, 'reports/q2/notes.docx'));

        $this->assertSame('docx-converter', $result->extractionMeta['converter']);
        $this->assertSame('reports/q2/notes.docx', $result->extractionMeta['source_path']);
        $this->assertSame('notes.docx', $result->extractionMeta['filename']);
        $this->assertArrayHasKey('duration_ms', $result->extractionMeta);
        $this->assertGreaterThanOrEqual(0, $result->extractionMeta['duration_ms']);
        $this->assertGreaterThanOrEqual(1, $result->extractionMeta['section_count']);
        $this->assertGreaterThanOrEqual(1, $result->extractionMeta['block_count']);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $result->sourceMimeType,
        );
        $this->assertSame([], $result->mediaItems);
    }

    public function test_body_only_docx_yields_no_headings(): void
    {
        $bytes = DocxFixtureBuilder::build([
            ['type' => 'body', 'text' => 'Just a single body paragraph.'],
        ]);
        $result = (new DocxConverter())->convert($this->sourceDoc($bytes, 'docs/plain.docx'));

        $this->assertStringStartsWith("# plain.docx\n\n", $result->markdown);
        $this->assertStringContainsString('Just a single body paragraph.', $result->markdown);
        $this->assertStringNotContainsString('## ', $result->markdown);
    }

    public function test_throws_runtime_exception_on_invalid_docx(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DocxConverter could not parse "docs\/garbage.docx"/');

        (new DocxConverter())->convert($this->sourceDoc('not a docx zip', 'docs/garbage.docx'));
    }

    private function sourceDoc(string $bytes, string $sourcePath): SourceDocument
    {
        return new SourceDocument(
            sourcePath: $sourcePath,
            mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            bytes: $bytes,
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );
    }
}
