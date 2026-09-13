<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\Drivers\TesseractOcrDriver;
use App\Services\Kb\Ocr\Drivers\VisionLlmOcrDriver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The drivers that rasterise PDFs themselves need Poppler's `pdftoppm` /
 * `pdfinfo` for a PDF — the preflight names a missing one BEFORE a
 * scanned-PDF job is queued (R14) — but a raster image never uses them,
 * so an image-only preflight is not refused for a dependency it does not need.
 */
final class PopplerPreflightTest extends TestCase
{
    #[Test]
    public function tesseract_reports_the_poppler_binaries_for_a_pdf_but_not_for_an_image(): void
    {
        config(['kb.ocr.tesseract.binary' => '/bin/sh', 'kb.ocr.tesseract.pdftoppm' => '/nonexistent/pdftoppm', 'kb.ocr.tesseract.pdfinfo' => '/bin/sh']);
        $driver = app(TesseractOcrDriver::class);

        $this->assertNull($driver->unavailableReason(false), 'an image needs only tesseract');
        $this->assertStringContainsString('pdftoppm', (string) $driver->unavailableReason(true));
        $this->assertStringContainsString('pdftoppm', (string) $driver->unavailableReason(), 'the default is the conservative (PDF) answer');
        $this->assertFalse($driver->isAvailable());

        config(['kb.ocr.tesseract.pdftoppm' => '/bin/sh', 'kb.ocr.tesseract.pdfinfo' => '/nonexistent/pdfinfo']);
        $this->assertStringContainsString('pdfinfo', (string) $driver->unavailableReason(true));

        config(['kb.ocr.tesseract.pdfinfo' => '/bin/sh']);
        $this->assertNull($driver->unavailableReason(true));

        config(['kb.ocr.tesseract.binary' => '/nonexistent/tesseract']);
        $this->assertStringContainsString('tesseract binary', (string) $driver->unavailableReason(false), 'the engine itself comes first');
    }

    #[Test]
    public function vision_llm_reports_the_poppler_binaries_for_a_pdf_but_not_for_an_image(): void
    {
        config([
            'ai.default' => 'openai', 'kb.ocr.vision_llm.provider' => null, 'ai.providers.openai.key' => 'k',
            'kb.ocr.vision_llm.pdftoppm' => '/nonexistent/pdftoppm', 'kb.ocr.vision_llm.pdfinfo' => '/bin/sh',
        ]);
        $driver = app(VisionLlmOcrDriver::class);

        $this->assertNull($driver->unavailableReason(false));
        $this->assertStringContainsString('pdftoppm', (string) $driver->unavailableReason(true));

        config(['ai.providers.openai.key' => '']);
        $this->assertStringContainsString('no API key', (string) $driver->unavailableReason(false), 'the credential comes first');
    }
}
