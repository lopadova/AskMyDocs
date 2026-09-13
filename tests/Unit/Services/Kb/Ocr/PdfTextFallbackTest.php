<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\PdfTextFallback;
use Tests\TestCase;

/**
 * The ONE `pdftotext` decision the converter and the estimate share: text
 * found → the PDF is ingested as text; nothing, or no binary → OCR.
 */
final class PdfTextFallbackTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_text_found_by_the_binary_is_returned_per_page(): void
    {
        config(['kb.pdf.pdftotext_bin' => $this->stub("printf 'First page has enough text here\\fSecond page too, plenty of it\\f'")]);

        $pages = (new PdfTextFallback)->textPages('%PDF-1.4 unreadable');

        $this->assertNotNull($pages);
        $this->assertCount(2, $pages);
        $this->assertStringContainsString('First page', $pages[0]);
        $this->assertStringContainsString('Second page', $pages[1]);
    }

    public function test_no_text_or_a_failing_binary_means_ocr(): void
    {
        config(['kb.pdf.pdftotext_bin' => $this->stub("printf '  \\f \\f'")]);
        $this->assertNull((new PdfTextFallback)->textPages('%PDF-1.4 scanned'), 'whitespace only → OCR');

        config(['kb.pdf.pdftotext_bin' => $this->stub('exit 3')]);
        $this->assertNull((new PdfTextFallback)->textPages('%PDF-1.4 x'), 'binary fails → OCR');

        config(['kb.pdf.pdftotext_bin' => '/nonexistent/pdftotext']);
        $this->assertNull((new PdfTextFallback)->textPages('%PDF-1.4 x'), 'binary missing → OCR');
        $this->assertSame([], glob(sys_get_temp_dir().'/kb_pdf_*') ?: [], 'no temporary PDF is left behind');
    }

    private function stub(string $script): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdftotext_stub_');
        $this->tempFiles[] = $path;
        file_put_contents($path, "#!/bin/sh\n{$script}\n");
        chmod($path, 0755);

        return $path;
    }
}
