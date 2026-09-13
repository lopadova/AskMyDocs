<?php

declare(strict_types=1);

namespace App\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Ocr\OcrService;
use App\Services\Kb\Ocr\PdfTextFallback;
use App\Services\Kb\Ocr\PdfTextLayerProbe;
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
 *
 * v8.36 / ADR 0029 — OCR fallback for scanned PDFs. The pipeline registry
 * resolves converters by MIME alone, so "is there a text layer?" cannot be
 * decided in `supports()`; it is decided HERE, after the text-layer probe:
 * when `kb.ocr.enabled` is true and the probe finds no text (or the ingest
 * metadata carries `ocr.force`), conversion is delegated to the same
 * {@see OcrService} the image converter uses. `extractionMeta.text_layer_probe`
 * records the verdict either way. With the flag off this class is byte for
 * byte the v8.35 one.
 */
final class PdfConverter implements ConverterInterface
{
    /**
     * Nullable so the pure unit tests (and any legacy direct construction)
     * keep working; the container always injects it.
     */
    private ?PdfTextFallback $pdfTextFallback = null;

    public function __construct(private readonly ?OcrService $ocr = null, ?PdfTextFallback $pdfTextFallback = null)
    {
        $this->pdfTextFallback = $pdfTextFallback;
    }

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
        // Null only when OCR is disabled; with OCR on the route always
        // carries the probe verdict, so the meta below never reads a null.
        $ocrRoute = $this->ocrRoute($doc);
        if ($ocrRoute !== null && $ocrRoute['reason'] !== null) {
            $converted = $this->ocr->convert($doc, $this->name(), reason: $ocrRoute['reason']);

            return new ConvertedDocument(
                markdown: $converted->markdown,
                mediaItems: $converted->mediaItems,
                extractionMeta: array_merge($converted->extractionMeta, ['text_layer_probe' => $ocrRoute['probe']]),
                sourceMimeType: $converted->sourceMimeType,
            );
        }

        $start = hrtime(true);
        $strategy = 'smalot';
        // OFF path: `$ocrRoute` is null and nothing below reads it — the
        // pdftotext pages exist only when an `unreadable` probe already ran
        // the fallback and found text (OCR on), and are read through the
        // null-safe local below.
        $pdftotextPages = $ocrRoute !== null && isset($ocrRoute['pages']) && is_array($ocrRoute['pages']) ? $ocrRoute['pages'] : null;

        try {
            // An `unreadable` probe already ran pdftotext and found text:
            // keep those pages instead of failing smalot a second time.
            $pages = $pdftotextPages ?? $this->extractWithSmalot($doc->bytes);
            $strategy = $pdftotextPages !== null ? 'pdftotext' : 'smalot';
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
            extractionMeta: array_merge([
                'converter' => $this->name(),
                'duration_ms' => $durationMs,
                'page_count' => count($pages),
                'extraction_strategy' => $strategy,
                'source_path' => $doc->sourcePath,
                'filename' => $filename,
            ], $ocrRoute !== null ? ['text_layer_probe' => $ocrRoute['probe']] : []),
            sourceMimeType: $doc->mimeType,
        );
    }

    /**
     * Decide whether this PDF goes to OCR. Null only when OCR is disabled.
     * With OCR on the array is always returned: `reason === null` = the
     * text-layer path (a confident `present` verdict, or pages the pdftotext
     * fallback already produced when the probe's parser could not read the
     * file — a parser failure is NOT "no text", and must never be billed as
     * a scan); a non-null `reason` = OCR. `probe` always carries the verdict.
     *
     * @return array{reason: ?string, probe: string, pages?: list<string>}|null
     */
    private function ocrRoute(SourceDocument $doc): ?array
    {
        if (! $this->ocrEnabled()) {
            return null;
        }
        if (OcrService::isForced($doc->metadata)) {
            return ['reason' => 'forced', 'probe' => 'skipped'];
        }
        $probe = $this->ocr->probe()->probe($doc->bytes);
        if ($probe['verdict'] === PdfTextLayerProbe::PRESENT) {
            return ['reason' => null, 'probe' => PdfTextLayerProbe::PRESENT];
        }
        if ($probe['verdict'] === PdfTextLayerProbe::MIXED) {
            // Text pages AND scanned pages (a typed cover over scanned body
            // pages): the whole document goes to OCR so no page is lost — the
            // text pages are OCR'd too; the probe names the scanned ones.
            return ['reason' => 'mixed_pdf', 'probe' => PdfTextLayerProbe::MIXED, 'scanned_pages' => $probe['scanned_pages']];
        }
        if ($probe['verdict'] === PdfTextLayerProbe::UNREADABLE) {
            try {
                $pages = $this->extractWithPdftotext($doc->bytes);
            } catch (Throwable) {
                return ['reason' => 'scanned_pdf', 'probe' => PdfTextLayerProbe::UNREADABLE.':pdftotext_failed'];
            }
            if ($this->hasText($pages)) {
                return ['reason' => null, 'probe' => PdfTextLayerProbe::UNREADABLE.':pdftotext', 'pages' => $pages];
            }

            return ['reason' => 'scanned_pdf', 'probe' => PdfTextLayerProbe::UNREADABLE.':pdftotext_empty'];
        }

        return ['reason' => 'scanned_pdf', 'probe' => $probe['verdict']];
    }

    private function hasText(array $pages): bool
    {
        return $this->fallback()->hasText($pages);
    }

    private function ocrEnabled(): bool
    {
        return $this->ocr !== null && $this->ocr->enabled();
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
     * The `pdftotext` fallback — ONE implementation, shared with the cost
     * estimate so both answer "does this unreadable PDF have text?" alike.
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
        return $this->fallback()->extract($bytes);
    }

    private function fallback(): PdfTextFallback
    {
        return $this->pdfTextFallback ??= new PdfTextFallback();
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
