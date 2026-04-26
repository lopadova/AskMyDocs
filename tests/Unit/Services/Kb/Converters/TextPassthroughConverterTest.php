<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Converters\TextPassthroughConverter;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextPassthroughConverterTest extends TestCase
{
    public function test_implements_converter_interface(): void
    {
        $this->assertInstanceOf(ConverterInterface::class, new TextPassthroughConverter());
    }

    public function test_name_is_stable_kebab_identifier(): void
    {
        $this->assertSame('text-passthrough', (new TextPassthroughConverter())->name());
    }

    #[DataProvider('supportedMimeProvider')]
    public function test_supports_plain_text_only(string $mime, bool $expected): void
    {
        $this->assertSame($expected, (new TextPassthroughConverter())->supports($mime));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function supportedMimeProvider(): iterable
    {
        yield 'text/plain'                  => ['text/plain', true];
        yield 'text/markdown rejected'      => ['text/markdown', false];
        yield 'text/x-markdown rejected'    => ['text/x-markdown', false];
        yield 'application/pdf rejected'    => ['application/pdf', false];
        yield 'empty mime rejected'         => ['', false];
    }

    public function test_convert_wraps_text_in_h1_filename_header(): void
    {
        $c = new TextPassthroughConverter();
        $doc = new SourceDocument(
            sourcePath: 'notes/release.txt',
            mimeType: 'text/plain',
            bytes: 'Plain text body.',
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $result = $c->convert($doc);

        $this->assertInstanceOf(ConvertedDocument::class, $result);
        // Plain text wrapped: filename basename becomes the H1 header so
        // MarkdownChunker can section it into a one-section chunk under
        // section_aware mode (instead of falling back to paragraph_split
        // and losing the heading_path breadcrumb).
        $this->assertStringStartsWith("# release.txt\n\n", $result->markdown);
        $this->assertStringContainsString('Plain text body.', $result->markdown);
        $this->assertSame('text/plain', $result->sourceMimeType);
        $this->assertSame([], $result->mediaItems);
        $this->assertSame('text-passthrough', $result->extractionMeta['converter']);
        $this->assertSame('notes/release.txt', $result->extractionMeta['source_path']);
        $this->assertSame('release.txt', $result->extractionMeta['filename']);
    }

    public function test_convert_preserves_empty_body(): void
    {
        $c = new TextPassthroughConverter();
        $doc = new SourceDocument(
            sourcePath: 'empty.txt',
            mimeType: 'text/plain',
            bytes: '',
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $result = $c->convert($doc);

        // Empty body still gets the H1 header — downstream chunker decides
        // whether to emit a chunk (MarkdownChunker returns [] for body that
        // is whitespace-only after frontmatter strip; the header alone with
        // no body is whitespace-only after strip).
        $this->assertStringStartsWith("# empty.txt\n\n", $result->markdown);
    }
}
