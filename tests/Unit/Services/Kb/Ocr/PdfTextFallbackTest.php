<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\OcrLimitExceededException;
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

    /**
     * The threshold is per page, as the probe applies it: several short
     * pages that only ADD UP to `min_text_chars` are scanned pages, and a
     * zero threshold counts any non-blank page.
     */
    public function test_the_text_threshold_is_applied_per_page_like_the_probe(): void
    {
        config(['kb.ocr.text_layer_probe.min_text_chars' => 20]);
        $fallback = new PdfTextFallback;
        $this->assertFalse($fallback->hasText(['seven c', 'seven c', 'seven c', 'seven c']), 'four short pages do not add up to a text layer');
        $this->assertTrue($fallback->hasText(['', 'twenty-characters!!!', '']), 'one qualifying page is enough');

        config(['kb.ocr.text_layer_probe.min_text_chars' => 0]);
        $this->assertFalse($fallback->hasText(['   ', "\n"]), 'blank pages never count');
        $this->assertTrue($fallback->hasText(['   ', 'x']), 'any character counts at a zero threshold');
    }

    public function test_the_temporary_copy_is_removed_after_a_successful_run(): void
    {
        config(['kb.pdf.pdftotext_bin' => $this->stub("printf 'Enough text on this page to count\f'")]);
        $before = glob(sys_get_temp_dir().'/kb_pdf_*') ?: [];

        $this->assertNotNull((new PdfTextFallback)->textPages('%PDF-1.4 x'));
        $this->assertSame($before, glob(sys_get_temp_dir().'/kb_pdf_*') ?: [], 'no temporary PDF is left behind');
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

    /**
     * The fallback runs before OCR, outside any run budget: a run past
     * KB_PDFTOTEXT_TIMEOUT is the deterministic `run_too_long` refusal —
     * never "no text, go to OCR" and never a generic error a job retries —
     * and the temporary copy is removed.
     */
    public function test_a_run_past_the_timeout_is_a_terminal_refusal_not_a_hand_off_to_ocr(): void
    {
        config(['kb.pdf.pdftotext_bin' => $this->stub('sleep 5'), 'kb.pdf.pdftotext_timeout' => 1]);
        $before = glob(sys_get_temp_dir().'/kb_pdf_*') ?: [];

        try {
            (new PdfTextFallback)->textPages('%PDF-1.4 malformed');
            $this->fail('a run past the timeout must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('run_too_long', $e->reason);
            $this->assertStringContainsString('KB_PDFTOTEXT_TIMEOUT', $e->getMessage());
        }
        $this->assertSame($before, glob(sys_get_temp_dir().'/kb_pdf_*') ?: [], 'no temporary PDF is left behind');
    }

    /**
     * PR #492 Copilot round-6 — `Process` buffers ALL stdout in memory until
     * `getOutput()` is called, and the input byte/page caps bound the SOURCE
     * PDF, not what a pathological content stream (a decompression-bomb
     * style PDF, well within the upload cap) can expand INTO as extracted
     * text. A run whose output crosses `kb.pdf.pdftotext_max_output_bytes`
     * is refused the same deterministic way a timeout is — never "here is a
     * truncated document" and never a generic error a job retries — and the
     * temporary copy is still removed.
     */
    public function test_output_over_the_configured_cap_is_a_terminal_refusal_not_a_truncated_document(): void
    {
        config([
            'kb.pdf.pdftotext_bin' => $this->stub("printf 'far more than ten bytes of extracted text right here\\f'"),
            'kb.pdf.pdftotext_max_output_bytes' => 10,
        ]);
        $before = glob(sys_get_temp_dir().'/kb_pdf_*') ?: [];

        try {
            (new PdfTextFallback)->textPages('%PDF-1.4 bomb');
            $this->fail('output over the cap must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('output_too_large', $e->reason);
            $this->assertStringContainsString('KB_PDFTOTEXT_MAX_OUTPUT_BYTES', $e->getMessage());
        }
        $this->assertSame($before, glob(sys_get_temp_dir().'/kb_pdf_*') ?: [], 'no temporary PDF is left behind');
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
