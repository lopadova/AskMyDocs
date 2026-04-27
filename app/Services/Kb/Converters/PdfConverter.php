<?php

declare(strict_types=1);

namespace App\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * PDF → markdown converter.
 *
 * Primary strategy: smalot/pdfparser (pure PHP, no system deps). Walks the
 * PDF object tree, extracts text per page, normalises whitespace, and emits
 * markdown shaped as `# {basename}` followed by `## Page N` markers for each
 * extracted page. That markdown shape is intentional: PdfPageChunker (T1.7)
 * owns `pdf` source-type routing via the registry's first-match-wins rule
 * and uses those markers to slice one chunk per non-empty page.
 *
 * Fallback strategy: invokes the `pdftotext` binary from Poppler when
 * smalot rejects the file (XFA forms, certain encrypted streams, malformed
 * trailers). Recorded in `extractionMeta.extraction_strategy`. If neither
 * strategy works, throws a RuntimeException so the ingest call surfaces a
 * clear failure to the operator instead of persisting an empty document.
 *
 * Per LESSONS T1.3 rule: `extractionMeta['filename'] = basename($doc->sourcePath)`
 * so the downstream chunker (and admin observability surfaces) attribute
 * chunks back to the source file.
 */
final class PdfConverter implements ConverterInterface
{
    public function name(): string
    {
        return 'pdf-converter';
    }

    public function supports(string $mimeType): bool
    {
        return $mimeType === 'application/pdf';
    }

    public function convert(SourceDocument $doc): ConvertedDocument
    {
        $start = hrtime(true);
        $strategy = 'smalot';

        try {
            $pages = $this->extractWithSmalot($doc->bytes);
        } catch (Throwable $smalotError) {
            try {
                $pages = $this->extractWithPdftotext($doc->bytes);
                $strategy = 'pdftotext';
            } catch (Throwable $fallbackError) {
                throw new \RuntimeException(sprintf(
                    'PdfConverter could not extract text from "%s": smalot failed (%s) and pdftotext fallback failed (%s).',
                    $doc->sourcePath,
                    $smalotError->getMessage(),
                    $fallbackError->getMessage(),
                ), previous: $fallbackError);
            }
        }

        $filename = basename($doc->sourcePath);
        $markdown = $this->renderMarkdown($filename, $pages);
        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return new ConvertedDocument(
            markdown: $markdown,
            mediaItems: [],
            extractionMeta: [
                'converter' => $this->name(),
                'duration_ms' => $durationMs,
                'page_count' => count($pages),
                'extraction_strategy' => $strategy,
                'source_path' => $doc->sourcePath,
                'filename' => $filename,
            ],
            sourceMimeType: $doc->mimeType,
        );
    }

    /**
     * @return list<string>  one element per page
     */
    private function extractWithSmalot(string $bytes): array
    {
        $pdf = (new Parser())->parseContent($bytes);
        $pages = $pdf->getPages();
        if ($pages === []) {
            throw new \RuntimeException('smalot/pdfparser returned 0 pages');
        }

        $out = [];
        foreach ($pages as $page) {
            $out[] = $page->getText();
        }
        return $out;
    }

    /**
     * Fallback to the `pdftotext` binary from Poppler. The `\f` form-feed
     * character separates pages in pdftotext's output, so we split on it
     * to reconstruct a per-page array matching the smalot shape.
     *
     * @return list<string>
     *
     * @throws ProcessRuntimeException  when the `pdftotext` binary is missing
     *                                  on PATH OR fails non-zero on the input.
     * @throws \RuntimeException        when the temp file required for the
     *                                  fallback can't be created/written.
     */
    private function extractWithPdftotext(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kb_pdf_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw new \RuntimeException('Failed to write temporary PDF file for pdftotext fallback');
        }

        try {
            $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $tmp, '-']);
            $process->mustRun();
            $text = $process->getOutput();
            $pages = preg_split("/\f/", $text);
            if ($pages === false || $pages === []) {
                return [$text];
            }
            // pdftotext frequently emits a trailing form-feed after the last
            // page, which can surface here as one or more trailing empty OR
            // whitespace-only elements (the binary often appends `\n` or
            // spaces after the form-feed too). Loop-pop ALL such phantom
            // trailing pages so page_count and later page numbering stay
            // aligned with the real page count.
            while ($pages !== [] && trim((string) end($pages)) === '') {
                array_pop($pages);
            }
            return $pages === [] ? [$text] : $pages;
        } finally {
            // Per CLAUDE.md R7 (no @-silenced errors): explicit guard +
            // unsuppressed unlink. tempnam() always returns a writable path,
            // so a missing file at this point would only happen if another
            // process raced us — guarding with is_file() handles that.
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * Renders the per-page markdown. Pages whose text is empty/whitespace
     * after cleanText() are SKIPPED — emitting `## Page N\n\n` with no body
     * would otherwise produce heading-only chunks that pollute the vector
     * index (real-world trigger: scanned/image-only PDFs where smalot
     * extracts no text at all).
     *
     * If EVERY page is empty, returns an empty string so the downstream
     * MarkdownChunker returns []. This prevents meaningless chunk and
     * embedding content from being created; whether an empty document
     * row is still persisted by DocumentIngestor is a separate pipeline
     * concern this converter does not control. Mirrors TextPassthrough
     * Converter's empty-body semantics.
     *
     * @param  list<string>  $pages
     */
    private function renderMarkdown(string $filename, array $pages): string
    {
        // Accumulate sections into an array + implode() once at the end
        // instead of `.=` in the loop. For typical PDFs this is a wash;
        // for large multi-hundred-page documents it avoids the quadratic
        // realloc cost of repeated PHP string concatenation.
        $sections = [];
        foreach ($pages as $i => $pageText) {
            $cleaned = $this->cleanText($pageText);
            if ($cleaned === '') {
                continue;
            }
            $sections[] = '## Page ' . ($i + 1) . "\n\n{$cleaned}\n\n";
        }
        if ($sections === []) {
            return '';
        }
        return "# {$filename}\n\n" . rtrim(implode('', $sections)) . "\n";
    }

    private function cleanText(string $text): string
    {
        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
