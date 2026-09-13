<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr\Drivers\Concerns;

use App\Services\Kb\Ocr\OcrDriverUnavailableException;
use App\Services\Kb\Ocr\OcrLimitExceededException;
use App\Services\Kb\Ocr\OcrRequest;
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
    protected function rasterise(OcrRequest $request, string $pdftoppmBinary, int $dpi, int $timeout = 300): array
    {
        $dir = sys_get_temp_dir().'/kb_ocr_'.bin2hex(random_bytes(6));
        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
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

        if (! $request->isPdf()) {
            return ['dir' => $dir, 'pages' => [1 => $input]];
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
        // The SOURCE byte cap (KB_OCR_MAX_BYTES) says nothing about what a
        // page renders to: a small file can declare a 200-inch MediaBox and
        // rasterise to gigapixels. `-W/-H` clip the rendered area to a fixed
        // pixel box (poppler only clips — a smaller page keeps its size), so
        // the render itself is bounded before any byte check can run.
        $maxPx = max(500, (int) config('kb.ocr.raster.max_page_px', 6000));
        $process = new Process([$pdftoppmBinary, '-r', (string) min(600, max(50, $dpi)), '-f', '1', '-l', (string) $maxPages, '-W', (string) $maxPx, '-H', (string) $maxPx, '-png', $input, $dir.'/page']);
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

        // RENDERED byte cap (ADR 0029 §4): what leaves for a remote vision
        // provider — or what a local engine has to decode — is the rendered
        // page, not the source file. A page over the cap is a deterministic
        // refusal, raised before any egress and never retried.
        $maxRenderedBytes = max(1, (int) config('kb.ocr.raster.max_page_bytes', 10485760));
        foreach ($pages as $number => $path) {
            $size = (int) (filesize($path) ?: 0);
            if ($size <= $maxRenderedBytes) {
                continue;
            }
            $this->cleanupAfterFailure($dir, $e = new OcrLimitExceededException(sprintf(
                'OCR refused for "%s": page %d renders to %d bytes, over KB_OCR_RASTER_MAX_PAGE_BYTES (%d).',
                $request->filename,
                $number,
                $size,
                $maxRenderedBytes,
            ), 'rendered_page_too_large'));
            throw $e;
        }

        return ['dir' => $dir, 'pages' => $pages];
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
            if (is_file($file) && ! @unlink($file)) {
                $left[] = $file;
            }
        }
        if ($left === [] && ! @rmdir($dir)) {
            $left[] = $dir;
        }
        if ($left === []) {
            return;
        }
        Log::error('OCR temp cleanup failed: document bytes remain on disk', ['dir' => $dir, 'left' => $left]);
        throw new \RuntimeException(sprintf('OCR temp cleanup failed, %d item(s) remain under %s.', count($left), $dir));
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
