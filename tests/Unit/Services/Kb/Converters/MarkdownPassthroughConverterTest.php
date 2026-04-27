<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Converters\MarkdownPassthroughConverter;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownPassthroughConverterTest extends TestCase
{
    public function test_implements_converter_interface(): void
    {
        $this->assertInstanceOf(ConverterInterface::class, new MarkdownPassthroughConverter());
    }

    public function test_name_is_stable_kebab_identifier(): void
    {
        $this->assertSame('markdown-passthrough', (new MarkdownPassthroughConverter())->name());
    }

    #[DataProvider('supportedMimeProvider')]
    public function test_supports_markdown_mime_types(string $mime, bool $expected): void
    {
        $this->assertSame($expected, (new MarkdownPassthroughConverter())->supports($mime));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function supportedMimeProvider(): iterable
    {
        yield 'text/markdown'            => ['text/markdown', true];
        yield 'text/x-markdown'          => ['text/x-markdown', true];
        yield 'application/pdf rejected' => ['application/pdf', false];
        yield 'text/plain rejected'      => ['text/plain', false];
        yield 'empty mime rejected'      => ['', false];
    }

    public function test_convert_returns_bytes_unchanged_with_meta(): void
    {
        $c = new MarkdownPassthroughConverter();
        $doc = new SourceDocument(
            sourcePath: 'a.md',
            mimeType: 'text/markdown',
            bytes: "# Hello\n\nbody",
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $result = $c->convert($doc);

        $this->assertInstanceOf(ConvertedDocument::class, $result);
        $this->assertSame("# Hello\n\nbody", $result->markdown);
        $this->assertSame('text/markdown', $result->sourceMimeType);
        $this->assertSame([], $result->mediaItems);
        $this->assertSame('markdown-passthrough', $result->extractionMeta['converter']);
        $this->assertSame('a.md', $result->extractionMeta['source_path']);
        $this->assertArrayHasKey('duration_ms', $result->extractionMeta);
    }

    public function test_extraction_meta_propagates_filename_for_chunker_consumption(): void
    {
        $c = new MarkdownPassthroughConverter();
        $doc = new SourceDocument(
            sourcePath: 'docs/runbooks/incident.md',
            mimeType: 'text/markdown',
            bytes: '# title',
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $result = $c->convert($doc);

        // MarkdownChunker reads extractionMeta['filename'] (T1.2 contract). The
        // converter must populate it from the source path's basename so the
        // pipeline doesn't need a side-channel for the filename.
        $this->assertSame('incident.md', $result->extractionMeta['filename']);
    }
}
