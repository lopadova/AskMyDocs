<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\Drivers\DoclingOcrDriver;
use App\Services\Kb\Ocr\Drivers\TesseractOcrDriver;
use App\Services\Kb\Ocr\Drivers\VisionLlmOcrDriver;
use Tests\TestCase;

/**
 * ADR 0029 §5 — the run key is content-addressed over the ENGINE: for a
 * local driver the executables are the engine. Another tesseract build,
 * another rasteriser or another docling install is another transcript,
 * never a reused run.
 */
final class LocalDriverFingerprintTest extends TestCase
{
    public function test_tesseract_fingerprint_names_its_executables(): void
    {
        config(['kb.ocr.tesseract.lang' => 'eng', 'kb.ocr.tesseract.dpi' => 200, 'kb.ocr.tesseract.binary' => 'tesseract', 'kb.ocr.tesseract.pdftoppm' => 'pdftoppm', 'kb.ocr.tesseract.pdfinfo' => 'pdfinfo']);
        $before = app(TesseractOcrDriver::class)->fingerprint();
        $this->assertSame('lang=eng;dpi=200;bin=tesseract;pdftoppm=pdftoppm;pdfinfo=pdfinfo', $before);

        config(['kb.ocr.tesseract.binary' => '/opt/tesseract-5.4/bin/tesseract']);
        $binary = app(TesseractOcrDriver::class)->fingerprint();
        $this->assertNotSame($before, $binary, 'another tesseract build is another engine');

        config(['kb.ocr.tesseract.pdftoppm' => '/opt/poppler-24/bin/pdftoppm']);
        $rasteriser = app(TesseractOcrDriver::class)->fingerprint();
        $this->assertNotSame($binary, $rasteriser, 'another rasteriser is another engine');

        config(['kb.ocr.tesseract.pdfinfo' => '/opt/poppler-24/bin/pdfinfo']);
        $this->assertNotSame($rasteriser, app(TesseractOcrDriver::class)->fingerprint(), 'another pdfinfo is another engine');
    }

    public function test_vision_llm_fingerprint_names_its_rasteriser(): void
    {
        config(['ai.default' => 'openai', 'kb.ocr.vision_llm.provider' => 'openai', 'kb.ocr.vision_llm.model' => 'gpt-4o', 'ai.providers.openai.key' => 'k', 'kb.ocr.vision_llm.pdftoppm' => 'pdftoppm', 'kb.ocr.vision_llm.pdfinfo' => 'pdfinfo']);
        $before = app(VisionLlmOcrDriver::class)->fingerprint();
        $this->assertStringContainsString(';pdftoppm=pdftoppm;pdfinfo=pdfinfo', $before);

        config(['kb.ocr.vision_llm.pdftoppm' => '/opt/poppler-24/bin/pdftoppm']);
        $this->assertNotSame($before, app(VisionLlmOcrDriver::class)->fingerprint(), 'another rasteriser is another engine');
    }

    /** Two installs under the same basename are two engines: the full path is the identity, not `docling`. */
    public function test_docling_fingerprint_is_the_full_executable_path(): void
    {
        config(['kb.ocr.docling.binary' => '/opt/docling-2.1/bin/docling']);
        $a = app(DoclingOcrDriver::class)->fingerprint();
        $this->assertSame('docling;bin=/opt/docling-2.1/bin/docling', $a);

        config(['kb.ocr.docling.binary' => '/opt/docling-2.2/bin/docling']);
        $this->assertNotSame($a, app(DoclingOcrDriver::class)->fingerprint(), 'same basename, another install, another engine');
    }
}
