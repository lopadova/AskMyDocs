<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use Illuminate\Support\Facades\Log;
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
     * The SAME per-page rule the text-layer probe applies to the parser's
     * text: a page carries text when, whitespace stripped, it holds at least
     * `text_layer_probe.min_text_chars` characters (any character at all
     * when the threshold is zero). The document has text when at least one
     * page qualifies — never when many short pages merely add up to the
     * threshold, which would send a scanned page down the text path and
     * silently skip its OCR.
     *
     * @param  list<string>  $pages
     */
    public function hasText(array $pages): bool
    {
        $minChars = max(0, (int) config('kb.ocr.text_layer_probe.min_text_chars', 20));
        foreach ($pages as $page) {
            $pageChars = mb_strlen((string) preg_replace('/\s+/u', '', $page));
            if ($minChars > 0 ? $pageChars >= $minChars : $pageChars > 0) {
                return true;
            }
        }

        return false;
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
                $pages = [$text];
            }
            // pdftotext frequently emits a trailing form-feed after the last
            // page, which surfaces here as one or more trailing empty OR
            // whitespace-only elements. Pop them all so page_count and later
            // page numbering stay aligned with the real page count.
            while ($pages !== [] && trim((string) end($pages)) === '') {
                array_pop($pages);
            }
        } catch (\Throwable $e) {
            // The run failed: the copy still has to go, but the failure the
            // caller sees is the run's — a cleanup that fails on top of it
            // is logged, not thrown over it.
            $this->removeTemp($tmp, $e);
            throw $e;
        }
        // The run succeeded: a copy of the document that cannot be removed
        // is a retained artifact in the shared temp directory, and that is a
        // failure the caller must see (R4/SEC-RETENTION-001), never a
        // success that silently kept the bytes behind.
        $this->removeTemp($tmp, null);

        return $pages === [] ? [$text] : $pages;
    }

    /**
     * Per CLAUDE.md R7 (no @-silenced errors): explicit guard + unsuppressed
     * unlink; is_file() covers a concurrent removal. With no exception in
     * flight a failed removal throws; with one, it is logged so the original
     * failure is the one that surfaces.
     */
    private function removeTemp(string $tmp, ?\Throwable $inFlight): void
    {
        if (! is_file($tmp)) {
            return;
        }
        if (unlink($tmp)) {
            return;
        }
        if ($inFlight === null) {
            throw new \RuntimeException("Failed to remove the temporary PDF file {$tmp} after the pdftotext run.");
        }
        Log::warning('PdfTextFallback: temporary PDF file could not be removed after a failed pdftotext run', [
            'path' => $tmp,
            'exception' => $inFlight::class,
        ]);
    }
}
