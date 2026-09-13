<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr\Drivers\Concerns;

use App\Services\Kb\Ocr\OcrDriverUnavailableException;
use App\Services\Kb\Ocr\OcrLimitExceededException;
use App\Services\Kb\Ocr\OcrRequest;
use App\Services\Kb\Ocr\TiffFrameCounter;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Shared by the drivers that work on page images (tesseract, vision-llm):
 * writes the request bytes to a temp file and, for a PDF, rasterises every
 * page with `pdftoppm` (Poppler) into PNGs. Returns `[pageNumber => path]`.
 *
 * Every temp file is removed by the caller through `cleanup()` — no
 * `@`-silenced unlink (R7). Process arguments are arrays (SEC-SHELL-001).
 */
trait RasterisesPdf
{
    /**
     * @return array{dir: string, pages: array<int, string>}
     */
    protected function rasterise(OcrRequest $request, string $pdftoppmBinary, int $dpi, int $timeout = 300, string $pdfinfoBinary = 'pdfinfo'): array
    {
        // Private to the worker's user (0700): the source and its rendered
        // pages are document bytes on their way to an engine or a provider,
        // never readable by another account on the host while they sit here.
        $dir = sys_get_temp_dir().'/kb_ocr_'.bin2hex(random_bytes(6));
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Could not create OCR working directory {$dir}.");
        }

        $extension = $request->isPdf() ? 'pdf' : 'img';
        $input = $dir.'/input.'.$extension;
        if (file_put_contents($input, $request->bytes) === false) {
            // Never leave the working directory behind on a failed attempt
            // (SEC-RETENTION-001): nothing else will ever remove it.
            $this->cleanupAfterFailure($dir, $e = new \RuntimeException("Could not write OCR input to {$input}."));
            throw $e;
        }

        $maxPx = max(500, (int) config('kb.ocr.raster.max_page_px', 6000));
        if (! $request->isPdf()) {
            // An image IS the page — ONE page: the page cap counted every
            // TIFF frame (ADR 0029 §4), but this rasteriser hands the file to
            // the engine as a single image, so a multi-frame TIFF would be
            // metered for N pages and transcribed for one. The service
            // refuses it first (`acceptsMultiFrameImages()`); this is the
            // defence in depth behind that gate, never a silent first frame.
            $frames = TiffFrameCounter::count($request->bytes);
            if ($frames > 1) {
                $this->cleanupAfterFailure($dir, $e = new OcrLimitExceededException(sprintf(
                    'OCR refused for "%s": a %d-frame TIFF cannot be OCR\'d frame by frame by this driver; split it into one image per page.',
                    $request->filename,
                    $frames,
                ), 'multi_frame_image'));
                throw $e;
            }
            // The same pixel and byte bounds apply
            // before an engine decodes it or a provider receives it — a
            // highly compressed file with huge dimensions is a decode bomb
            // whatever the source byte cap said.
            $pages = [1 => $input];
            $this->assertRenderedBounds($dir, $pages, $request->filename, $maxPx);

            return ['dir' => $dir, 'pages' => $pages];
        }

        if ((new ExecutableFinder())->find($pdftoppmBinary) === null && ! is_executable($pdftoppmBinary)) {
            $this->cleanupAfterFailure($dir, $e = new OcrDriverUnavailableException(
                "pdftoppm binary \"{$pdftoppmBinary}\" not found — install poppler-utils or set KB_OCR_PDFTOPPM_BIN.",
            ));
            throw $e;
        }

        // DPI clamped to [50, 600]: below is unreadable, above is a memory
        // bomb on a 2 000-page scan (SEC-LIMITS-001). The driver's own timeout
        // bounds the render time; `-l KB_OCR_MAX_PAGES` bounds the render
        // WORK — a PDF the parser could not count (ADR 0029 §4) still renders
        // at most the cap, whatever its object table claims.
        $maxPages = max(1, (int) config('kb.ocr.max_pages', 200));
        $dpi = min(600, max(50, $dpi));
        // The SOURCE byte cap (KB_OCR_MAX_BYTES) says nothing about what a
        // page renders to: a small file can declare a 200-inch MediaBox and
        // rasterise to gigapixels. The bound is applied BEFORE the render, on
        // the page geometry `pdfinfo` reports: the DPI is lowered so that no
        // page's long side exceeds KB_OCR_RASTER_MAX_PAGE_PX, and a page that
        // would need less than the 50-DPI floor to fit is refused outright.
        // (`pdftoppm -W/-H` are crop sizes, not a scale bound: they would
        // silently discard the text outside the box, never shrink the render.)
        try {
            $dpi = $this->boundedDpi($input, $pdfinfoBinary, $maxPages, $dpi, $maxPx, $timeout, $request->filename);
        } catch (\Throwable $e) {
            $this->cleanupAfterFailure($dir, $e);
            throw $e;
        }
        $process = new Process([$pdftoppmBinary, '-r', (string) $dpi, '-f', '1', '-l', (string) $maxPages, '-png', $input, $dir.'/page']);
        $process->setTimeout(max(1, $timeout));
        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->cleanupAfterFailure($dir, $e);
            $stderr = trim(preg_replace('/\s+/', ' ', $process->getErrorOutput()) ?? '');
            throw new \RuntimeException(sprintf(
                'pdftoppm exited with code %s%s',
                (string) $process->getExitCode(),
                $stderr !== '' ? ': '.mb_substr($stderr, 0, 200) : '',
            ));
        } catch (\Throwable $e) {
            // Timeout or anything else: never leave the full scan in /tmp
            // (SEC-RETENTION-001) — the caller's try/finally starts only after
            // rasterise() has returned.
            $this->cleanupAfterFailure($dir, $e);
            throw $e;
        }

        $pages = [];
        foreach (glob($dir.'/page-*.png') ?: [] as $path) {
            if (preg_match('/page-(\d+)\.png$/', $path, $m) === 1) {
                $pages[(int) $m[1]] = $path;
            }
        }
        ksort($pages);

        if ($pages === []) {
            $this->cleanupAfterFailure($dir, $e = new \RuntimeException('pdftoppm produced no pages.'));
            throw $e;
        }

        // RENDERED bounds (ADR 0029 §4): what leaves for a remote vision
        // provider — or what a local engine has to decode — is the rendered
        // page, not the source file. The pixel box is re-measured on the PNG
        // that was actually produced (defence in depth behind the pre-render
        // DPI bound) and the byte cap applies to it; either over the limit is
        // a deterministic refusal, raised before any egress and never retried.
        $this->assertRenderedBounds($dir, $pages, $request->filename, $maxPx);

        return ['dir' => $dir, 'pages' => $pages];
    }

    /**
     * Every page image — rendered from a PDF or the input image itself — is
     * measured (`getimagesize`, header only) against the pixel box and its
     * file size against the byte cap before an engine decodes it or a
     * provider receives it. Over either bound: `rendered_page_too_large`,
     * the working directory removed. Undecodable: an error, never a page.
     *
     * @param  array<int, string>  $pages
     *
     * @throws OcrLimitExceededException
     */
    private function assertRenderedBounds(string $dir, array $pages, string $filename, int $maxPx): void
    {
        $maxRenderedBytes = max(1, (int) config('kb.ocr.raster.max_page_bytes', 10485760));
        foreach ($pages as $number => $path) {
            $size = (int) (filesize($path) ?: 0);
            $dimensions = $this->measureImage($path);
            if ($dimensions === null) {
                $this->cleanupAfterFailure($dir, $e = new \RuntimeException(sprintf('Unreadable page image for page %d of "%s".', $number, $filename)));
                throw $e;
            }
            [$width, $height] = $dimensions;
            if ($width > $maxPx || $height > $maxPx) {
                $this->cleanupAfterFailure($dir, $e = new OcrLimitExceededException(sprintf(
                    'OCR refused for "%s": page %d is %d×%d px, over KB_OCR_RASTER_MAX_PAGE_PX (%d).',
                    $filename,
                    $number,
                    $width,
                    $height,
                    $maxPx,
                ), 'rendered_page_too_large'));
                throw $e;
            }
            if ($size <= $maxRenderedBytes) {
                continue;
            }
            $this->cleanupAfterFailure($dir, $e = new OcrLimitExceededException(sprintf(
                'OCR refused for "%s": page %d is %d bytes, over KB_OCR_RASTER_MAX_PAGE_BYTES (%d).',
                $filename,
                $number,
                $size,
                $maxRenderedBytes,
            ), 'rendered_page_too_large'));
            throw $e;
        }
    }

    /**
     * The render DPI that keeps every page inside the pixel box, computed
     * from the page geometry `pdfinfo` reports (points, 72 per inch) for the
     * pages that will be rendered. A page that cannot fit even at the 50-DPI
     * floor is refused before anything is rendered (`rendered_page_too_large`);
     * a missing `pdfinfo` or an unparseable report is "cannot bound the
     * render here" — an unavailable driver, never an unbounded render.
     *
     * @throws OcrDriverUnavailableException
     * @throws OcrLimitExceededException
     */
    private function boundedDpi(string $input, string $pdfinfoBinary, int $maxPages, int $dpi, int $maxPx, int $timeout, string $filename): int
    {
        if ((new ExecutableFinder())->find($pdfinfoBinary) === null && ! is_executable($pdfinfoBinary)) {
            throw new OcrDriverUnavailableException(
                "pdfinfo binary \"{$pdfinfoBinary}\" not found — install poppler-utils or set KB_OCR_PDFINFO_BIN.",
            );
        }
        $process = new Process([$pdfinfoBinary, '-f', '1', '-l', (string) $maxPages, $input]);
        $process->setTimeout(max(1, $timeout));
        $process->run();
        // A PDF pdfinfo cannot read is still rendered (`-l` bounds the work);
        // only its geometry matters here, and every page line it did print
        // counts. No page line at all is an unbounded render: refuse.
        $report = $process->getOutput();
        $sides = [];
        if (preg_match_all('/^Page(?:\s+(\d+))?\s+size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/m', $report, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $sides[(int) ($m[1] !== '' ? $m[1] : 1)] = max((float) $m[2], (float) $m[3]);
            }
        }
        if ($sides === []) {
            throw new OcrLimitExceededException(sprintf(
                'OCR refused for "%s": pdfinfo reported no page size, so the render cannot be bounded to KB_OCR_RASTER_MAX_PAGE_PX (%d).',
                $filename,
                $maxPx,
            ), 'rendered_page_too_large');
        }
        $longest = max($sides);
        $fitDpi = (int) floor($maxPx * 72 / max(1.0, $longest));
        if ($fitDpi < 50) {
            $page = (int) array_search($longest, $sides, true);
            throw new OcrLimitExceededException(sprintf(
                'OCR refused for "%s": page %d is %.0f pt on its long side and would exceed KB_OCR_RASTER_MAX_PAGE_PX (%d) even at 50 DPI.',
                $filename,
                $page,
                $longest,
                $maxPx,
            ), 'rendered_page_too_large');
        }

        return min($dpi, $fitDpi);
    }

    /**
     * @return array{0: int, 1: int}|null width and height, null when the file is not a decodable image
     */
    private function measureImage(string $path): ?array
    {
        try {
            $info = getimagesize($path);
        } catch (\Throwable) {
            return null;
        }
        if ($info === false) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * Remove the working directory (the source copy + rendered pages). A
     * failed removal is never silent (SEC-RETENTION-001): what is left is
     * logged and the call throws, so a job cannot report success while
     * document bytes remain in /tmp. Callers on a failure path use
     * {@see cleanupAfterFailure()} so the primary error is not masked.
     */
    protected function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $left = [];
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file) && ! self::remove($file, false)) {
                $left[] = $file;
            }
        }
        if ($left === [] && ! self::remove($dir, true)) {
            $left[] = $dir;
        }
        if ($left === []) {
            return;
        }
        Log::error('OCR temp cleanup failed: document bytes remain on disk', ['dir' => $dir, 'left' => $left]);
        throw new \RuntimeException(sprintf('OCR temp cleanup failed, %d item(s) remain under %s.', count($left), $dir));
    }

    /**
     * One removal, never suppressed: a warning the runtime raises as an
     * ErrorException counts as a failure like a false return does.
     */
    private static function remove(string $path, bool $isDir): bool
    {
        try {
            return $isDir ? rmdir($path) : unlink($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Cleanup on a failure path: logs (the primary error is what the caller
     * rethrows) and never throws itself.
     */
    protected function cleanupAfterFailure(string $dir, \Throwable $primary): void
    {
        try {
            $this->cleanup($dir);
        } catch (\Throwable $e) {
            Log::error('OCR temp cleanup failed after a driver error', ['dir' => $dir, 'cleanup_error' => $e->getMessage(), 'primary_error' => $primary->getMessage()]);
        }
    }
}
