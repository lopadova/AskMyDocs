<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\Drivers\Concerns\RasterisesPdf;
use App\Services\Kb\Ocr\OcrDriver;
use App\Services\Kb\Ocr\OcrDriverUnavailableException;
use App\Services\Kb\Ocr\OcrMeteringMode;
use App\Services\Kb\Ocr\OcrPage;
use App\Services\Kb\Ocr\OcrRequest;
use App\Services\Kb\Ocr\OcrResult;
use App\Services\Kb\Ocr\OcrRunBudget;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Tesseract (local) — the free fallback. No layout analysis, no figures;
 * confidence per page from the TSV output (mean word confidence / 100).
 *
 * PDFs are rasterised page by page with `pdftoppm`. Process arguments are
 * arrays, never shell strings (SEC-SHELL-001).
 */
final class TesseractOcrDriver implements OcrDriver
{
    use RasterisesPdf;

    public function name(): string
    {
        return 'tesseract';
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function unavailableReason(bool $forPdf = true): ?string
    {
        $binary = (string) config('kb.ocr.tesseract.binary', 'tesseract');
        if ((new ExecutableFinder())->find($binary) === null && ! is_executable($binary)) {
            return 'tesseract binary not found — install tesseract-ocr or set KB_OCR_TESSERACT_BIN.';
        }
        if (! $forPdf) {
            return null;
        }

        // A PDF is rasterised here before the engine sees a page: the
        // Poppler binaries are as much a prerequisite as tesseract itself,
        // and the preflight must say so before a scanned-PDF job is queued.
        return $this->popplerUnavailableReason(
            (string) config('kb.ocr.tesseract.pdftoppm', 'pdftoppm'),
            (string) config('kb.ocr.tesseract.pdfinfo', 'pdfinfo'),
        );
    }

    public function isRemote(): bool
    {
        return false;
    }

    public function fingerprint(): string
    {
        return sprintf('lang=%s;dpi=%d', (string) config('kb.ocr.tesseract.lang', 'eng'), (int) config('kb.ocr.tesseract.dpi', 200));
    }

    /** Page images are rendered up to KB_OCR_MAX_PAGES and each page runs under the process timeout: bounded by construction. */
    public function boundsWorkWithoutPageCount(): bool
    {
        return true;
    }

    /** The rasteriser hands one image per file; tesseract would read the first frame only. */
    public function acceptsMultiFrameImages(): bool
    {
        return false;
    }

    public function meteringMode(): OcrMeteringMode
    {
        return OcrMeteringMode::PerPage;
    }

    public function recognise(OcrRequest $request): OcrResult
    {
        $reason = $this->unavailableReason($request->isPdf());
        if ($reason !== null) {
            throw new OcrDriverUnavailableException($reason);
        }

        $binary = (string) config('kb.ocr.tesseract.binary', 'tesseract');
        $lang = (string) config('kb.ocr.tesseract.lang', 'eng');
        $timeout = (int) config('kb.ocr.tesseract.timeout', 300);
        // Every process this run starts is bounded by what is left of the run
        // budget (KB_OCR_JOB_TIMEOUT): the work can never exceed it, whatever
        // the per-page timeouts add up to over the page cap.
        $budget = OcrRunBudget::start();

        $raster = $this->rasterise(
            $request,
            (string) config('kb.ocr.tesseract.pdftoppm', 'pdftoppm'),
            (int) config('kb.ocr.tesseract.dpi', 200),
            $budget->bound($timeout),
            (string) config('kb.ocr.tesseract.pdfinfo', 'pdfinfo'),
        );

        try {
            $pages = [];
            foreach ($raster['pages'] as $number => $imagePath) {
                $budget->assertRemaining($request->filename);
                $pages[] = $this->recognisePage($binary, $lang, $budget->bound($timeout), $number, $imagePath);
            }
        } catch (\Throwable $e) {
            $this->cleanupAfterFailure($raster['dir'], $e);
            throw $e;
        }
        $this->cleanup($raster['dir']);

        return new OcrResult(driver: $this->name(), pages: $pages, meta: ['lang' => $lang]);
    }

    /**
     * Two bounded setup processes for a PDF (`pdfinfo` for the page bound,
     * `pdftoppm` for the render) plus two bounded processes (text + TSV) per
     * page, each under the same timeout — the declared worst case the run
     * lease is sized from (capped by the run budget the driver enforces,
     * OcrService::effectiveWorstCase()).
     */
    public function maxDurationSeconds(int $pages): int
    {
        $timeout = max(1, (int) config('kb.ocr.tesseract.timeout', 300));

        return $timeout * (2 + 2 * max(1, $pages));
    }

    private function recognisePage(string $binary, string $lang, int $timeout, int $number, string $imagePath): OcrPage
    {
        $text = new Process([$binary, $imagePath, 'stdout', '-l', $lang, '--psm', '3']);
        $text->setTimeout($timeout);
        try {
            $text->mustRun();
        } catch (ProcessFailedException $e) {
            // ProcessFailedException embeds stdout — the recognised text. Keep
            // the diagnostic bounded and free of page content (SEC-LOG-001).
            $stderr = trim(preg_replace('/\s+/', ' ', $text->getErrorOutput()) ?? '');
            throw new \RuntimeException(sprintf(
                'tesseract exited with code %s on page %d%s',
                (string) $text->getExitCode(),
                $number,
                $stderr !== '' ? ': '.mb_substr($stderr, 0, 200) : '',
            ));
        }

        // Confidence pass (TSV): mean of word-level `conf` values ≥ 0. A soft
        // signal — a failure here never discards the text pass.
        $confidence = null;
        try {
            $tsv = new Process([$binary, $imagePath, 'stdout', '-l', $lang, '--psm', '3', 'tsv']);
            $tsv->setTimeout($timeout);
            $tsv->mustRun();
            $confidence = $this->meanConfidenceFromTsv($tsv->getOutput());
        } catch (\Throwable) {
            $confidence = null;
        }

        $markdown = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text->getOutput())) ?? '');

        return new OcrPage(number: $number, markdown: $markdown, confidence: $confidence);
    }

    private function meanConfidenceFromTsv(string $tsv): ?float
    {
        $sum = 0.0;
        $count = 0;
        foreach (preg_split('/\r?\n/', $tsv) ?: [] as $i => $line) {
            if ($i === 0 || $line === '') {
                continue;
            }
            $cols = explode("\t", $line);
            if (count($cols) < 12) {
                continue;
            }
            $conf = (float) $cols[10];
            $word = trim($cols[11]);
            if ($conf < 0 || $word === '') {
                continue;
            }
            $sum += $conf;
            $count++;
        }

        return $count === 0 ? null : round($sum / $count / 100, 4);
    }
}
