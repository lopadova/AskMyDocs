<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Kb;

use App\Support\Kb\SourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceTypeTest extends TestCase
{
    public function test_string_values_match_db_column_convention(): void
    {
        // The string values are persisted as-is to knowledge_documents.source_type;
        // changing them would break every existing row. Lock the contract.
        $this->assertSame('markdown', SourceType::MARKDOWN->value);
        $this->assertSame('text', SourceType::TEXT->value);
        $this->assertSame('pdf', SourceType::PDF->value);
        $this->assertSame('docx', SourceType::DOCX->value);
        $this->assertSame('unknown', SourceType::UNKNOWN->value);
    }

    #[DataProvider('mimeProvider')]
    public function test_from_mime_resolves_supported_types(string $mime, SourceType $expected): void
    {
        $this->assertSame($expected, SourceType::fromMime($mime));
    }

    /**
     * @return iterable<string, array{0: string, 1: SourceType}>
     */
    public static function mimeProvider(): iterable
    {
        yield 'text/markdown'       => ['text/markdown', SourceType::MARKDOWN];
        yield 'text/x-markdown'     => ['text/x-markdown', SourceType::MARKDOWN];
        yield 'text/plain'          => ['text/plain', SourceType::TEXT];
        yield 'application/pdf'     => ['application/pdf', SourceType::PDF];
        yield 'docx mime'           => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            SourceType::DOCX,
        ];
        yield 'octet-stream → UNKNOWN' => ['application/octet-stream', SourceType::UNKNOWN];
        // v8.36 / ADR 0029 — images map to IMAGE; whether they are ACCEPTED is
        // decided at the entry points by `kb.ocr.enabled` (see the
        // supportedMimes()/knownExtensions() flag tests below).
        yield 'image/png → IMAGE'      => ['image/png', SourceType::IMAGE];
        yield 'image/jpeg → IMAGE'     => ['image/jpeg', SourceType::IMAGE];
        yield 'image/tiff → IMAGE'     => ['image/tiff', SourceType::IMAGE];
        yield 'image/webp → IMAGE'     => ['image/webp', SourceType::IMAGE];
        yield 'image/gif → UNKNOWN'    => ['image/gif', SourceType::UNKNOWN];
        yield 'empty → UNKNOWN'        => ['', SourceType::UNKNOWN];
    }

    #[DataProvider('extensionProvider')]
    public function test_from_extension_handles_dot_and_case_variations(string $ext, SourceType $expected): void
    {
        $this->assertSame($expected, SourceType::fromExtension($ext));
    }

    /**
     * @return iterable<string, array{0: string, 1: SourceType}>
     */
    public static function extensionProvider(): iterable
    {
        yield '.md'           => ['.md', SourceType::MARKDOWN];
        yield 'md'            => ['md', SourceType::MARKDOWN];
        yield 'markdown'      => ['markdown', SourceType::MARKDOWN];
        yield 'MD uppercase'  => ['MD', SourceType::MARKDOWN];
        yield 'txt'           => ['txt', SourceType::TEXT];
        yield 'pdf'           => ['pdf', SourceType::PDF];
        yield '.PDF uppercase' => ['.PDF', SourceType::PDF];
        yield 'docx'          => ['docx', SourceType::DOCX];
        yield 'png → IMAGE'   => ['png', SourceType::IMAGE];
        yield '.JPG → IMAGE'  => ['.JPG', SourceType::IMAGE];
        yield 'tiff → IMAGE'  => ['tiff', SourceType::IMAGE];
        yield 'unknown'       => ['gif', SourceType::UNKNOWN];
        yield 'empty'         => ['', SourceType::UNKNOWN];
    }

    public function test_to_mime_returns_canonical_mime_for_each_case(): void
    {
        $this->assertSame('text/markdown', SourceType::MARKDOWN->toMime());
        $this->assertSame('text/plain', SourceType::TEXT->toMime());
        $this->assertSame('application/pdf', SourceType::PDF->toMime());
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            SourceType::DOCX->toMime(),
        );
        $this->assertSame('application/octet-stream', SourceType::UNKNOWN->toMime());
    }

    public function test_is_binary_true_for_pdf_docx_and_image_only(): void
    {
        $this->assertFalse(SourceType::MARKDOWN->isBinary());
        $this->assertFalse(SourceType::TEXT->isBinary());
        $this->assertTrue(SourceType::PDF->isBinary());
        $this->assertTrue(SourceType::DOCX->isBinary());
        $this->assertTrue(SourceType::IMAGE->isBinary());
        $this->assertFalse(SourceType::UNKNOWN->isBinary());
    }

    public function test_token_returns_lowercase_db_string(): void
    {
        $this->assertSame('pdf', SourceType::PDF->token());
        $this->assertSame('markdown', SourceType::MARKDOWN->token());
    }

    public function test_known_extensions_includes_every_supported_format(): void
    {
        $exts = SourceType::knownExtensions();

        $this->assertContains('md', $exts);
        $this->assertContains('markdown', $exts);
        $this->assertContains('txt', $exts);
        $this->assertContains('pdf', $exts);
        $this->assertContains('docx', $exts);
        $this->assertNotContains('unknown', $exts);
    }

    /**
     * v8.36 / ADR 0029 — R43: the OFF path (default) is byte-identical to
     * v8.35; image entries appear only when the caller opts in with the flag.
     */
    public function test_images_are_listed_only_when_the_caller_includes_them(): void
    {
        $this->assertNotContains('image/png', SourceType::supportedMimes());
        $this->assertNotContains('png', SourceType::knownExtensions());
        $this->assertSame(
            ['text/markdown', 'text/x-markdown', 'text/plain', 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            SourceType::supportedMimes(false),
        );

        foreach (SourceType::imageMimes() as $mime) {
            $this->assertContains($mime, SourceType::supportedMimes(true));
            $this->assertSame(SourceType::IMAGE, SourceType::fromMime($mime));
        }
        foreach (SourceType::imageExtensions() as $ext) {
            $this->assertContains($ext, SourceType::knownExtensions(true));
            $this->assertSame(SourceType::IMAGE, SourceType::fromExtension($ext));
        }
        $this->assertSame('image/png', SourceType::IMAGE->toMime());
        $this->assertSame('image', SourceType::IMAGE->value);
    }
}
