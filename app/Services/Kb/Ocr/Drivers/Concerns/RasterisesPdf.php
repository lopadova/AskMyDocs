<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr\Drivers\Concerns;

use App\Services\Kb\Ocr\OcrDriverUnavailableException;
use App\Services\Kb\Ocr\OcrRequest;
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
            throw new \RuntimeException("Could not write OCR input to {$input}.");
        }

        if (! $request->isPdf()) {
            return ['dir' => $dir, 'pages' => [1 => $input]];
        }

        if ((new ExecutableFinder())->find($pdftoppmBinary) === null && ! is_executable($pdftoppmBinary)) {
            $this->cleanup($dir);
            throw new OcrDriverUnavailableException(
                "pdftoppm binary \"{$pdftoppmBinary}\" not found — install poppler-utils or set KB_OCR_PDFTOPPM_BIN.",
            );
        }

        // DPI clamped to [50, 600]: below is unreadable, above is a memory
        // bomb on a 2 000-page scan (SEC-LIMITS-001). The driver's own timeout
        // bounds the render, not a hard-coded one.
        $process = new Process([$pdftoppmBinary, '-r', (string) min(600, max(50, $dpi)), '-png', $input, $dir.'/page']);
        $process->setTimeout(max(1, $timeout));
        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->cleanup($dir);
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
            $this->cleanup($dir);
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
            $this->cleanup($dir);
            throw new \RuntimeException('pdftoppm produced no pages.');
        }

        return ['dir' => $dir, 'pages' => $pages];
    }

    protected function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }
}
