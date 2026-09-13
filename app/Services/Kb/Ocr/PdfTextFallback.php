<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * The `pdftotext` (Poppler) text extraction the PDF converter falls back to
 * when the pure-PHP parser cannot read a file — and the ONE place that
 * decision lives: `PdfConverter::ocrRoute()` sends an `unreadable` PDF to
 * OCR only when this fallback finds no text, and `OcrCostEstimator` must
 * answer the same question before commit, or the modal quotes OCR pages and
 * spend for a document the ingest will never OCR.
 */
final class PdfTextFallback
{
    /**
     * The per-page text `pdftotext` extracts, or null when the binary is
     * missing, fails on the input, or extracts no text at all (below the
     * probe's `min_text_chars` once whitespace is stripped) — i.e. null means
     * "this PDF goes to OCR" exactly as the converter decides it.
     *
     * @return list<string>|null
     */
    public function textPages(string $bytes): ?array
    {
        try {
            $pages = $this->extract($bytes);
        } catch (\Throwable) {
            return null;
        }

        return $this->hasText($pages) ? $pages : null;
    }

    /**
     * Same threshold the text-layer probe applies to the parser's text:
     * whitespace stripped, at least `text_layer_probe.min_text_chars`.
     *
     * @param  list<string>  $pages
     */
    public function hasText(array $pages): bool
    {
        $minChars = max(0, (int) config('kb.ocr.text_layer_probe.min_text_chars', 20));
        $chars = 0;
        foreach ($pages as $page) {
            $chars += mb_strlen((string) preg_replace('/\s+/u', '', $page));
        }

        return $chars >= $minChars;
    }

    /**
     * Run the `pdftotext` binary. The `\f` form-feed separates pages in its
     * output, so we split on it to reconstruct a per-page array.
     *
     * @return list<string>
     *
     * @throws ProcessRuntimeException  when the `pdftotext` binary is missing
     *                                  on PATH OR fails non-zero on the input.
     * @throws \RuntimeException        when the temp file required for the
     *                                  run can't be created/written.
     */
    public function extract(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kb_pdf_');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to create a temporary PDF file for pdftotext.');
        }

        try {
            // Inside the try: a write that fails after tempnam() created the
            // file (disk full, permissions) still reaches the cleanup below,
            // so a partial copy of the document never stays in the shared
            // temp directory (SEC-RETENTION-001).
            if (file_put_contents($tmp, $bytes) === false) {
                throw new \RuntimeException('Failed to write temporary PDF file for pdftotext fallback');
            }
            $process = new Process([(string) config('kb.pdf.pdftotext_bin', 'pdftotext'), '-layout', '-enc', 'UTF-8', $tmp, '-']);
            $process->mustRun();
            $text = $process->getOutput();
            $pages = preg_split("/\f/", $text);
            if ($pages === false || $pages === []) {
                return [$text];
            }
            // pdftotext frequently emits a trailing form-feed after the last
            // page, which surfaces here as one or more trailing empty OR
            // whitespace-only elements. Pop them all so page_count and later
            // page numbering stay aligned with the real page count.
            while ($pages !== [] && trim((string) end($pages)) === '') {
                array_pop($pages);
            }

            return $pages === [] ? [$text] : $pages;
        } finally {
            // Per CLAUDE.md R7 (no @-silenced errors): explicit guard +
            // unsuppressed unlink; is_file() covers a concurrent removal.
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
