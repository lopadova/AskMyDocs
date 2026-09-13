<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\OcrRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v8.36 / ADR 0029 — callers carry the FAMILY MIME (`image/png` for every
 * raster), so a driver that builds a data URL or picks an input format from
 * the MIME must read the magic bytes, not the label.
 */
final class OcrRequestTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function signatures(): array
    {
        return [
            'pdf' => ["%PDF-1.7\n%\xE2\xE3", 'application/pdf'],
            'png' => ["\x89PNG\r\n\x1a\n\0\0\0\rIHDR", 'image/png'],
            'jpeg' => ["\xFF\xD8\xFF\xE0\0\x10JFIF", 'image/jpeg'],
            'tiff little-endian' => ["II*\0\x08\0\0\0", 'image/tiff'],
            'tiff big-endian' => ["MM\0*\0\0\0\x08", 'image/tiff'],
            'webp' => ['RIFF'."\x24\0\0\0".'WEBPVP8 ', 'image/webp'],
        ];
    }

    #[DataProvider('signatures')]
    public function test_effective_mime_type_reads_the_magic_bytes_not_the_label(string $bytes, string $expected): void
    {
        $request = new OcrRequest($bytes, 'image/png', 'scan.bin');

        $this->assertSame($expected, $request->effectiveMimeType());
    }

    public function test_effective_mime_type_falls_back_to_the_declared_family_mime(): void
    {
        $request = new OcrRequest('no known signature here', 'image/PNG; charset=binary', 'scan.png');

        $this->assertSame('image/png', $request->effectiveMimeType());
    }

    public function test_is_pdf_still_trusts_the_declared_mime(): void
    {
        $this->assertTrue((new OcrRequest('bytes', 'application/pdf', 'a.pdf'))->isPdf());
        $this->assertFalse((new OcrRequest('%PDF-1.4', 'image/png', 'a.png'))->isPdf());
    }
}
