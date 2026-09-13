<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\OcrDriver;
use App\Services\Kb\Ocr\OcrDriverUnavailableException;
use App\Services\Kb\Ocr\OcrFigure;
use App\Services\Kb\Ocr\OcrMeteringMode;
use App\Services\Kb\Ocr\OcrPage;
use App\Services\Kb\Ocr\OcrRequest;
use App\Services\Kb\Ocr\OcrResult;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * IBM Docling (Apache-2.0, local process) — layout-aware conversion with
 * tables, figures and formulas as LaTeX. The default for sovereign installs.
 *
 * Invokes the `docling` CLI on a temp copy of the input with Markdown output
 * and referenced images, then splits the Markdown on Docling's page-break
 * markers into pages. Docling does not expose a per-page confidence for
 * PDFs, so `confidence` stays null.
 */
final class DoclingOcrDriver implements OcrDriver
{
    public function name(): string
    {
        return 'docling';
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function unavailableReason(): ?string
    {
        $binary = (string) config('kb.ocr.docling.binary', 'docling');
        if ((new ExecutableFinder())->find($binary) !== null || is_executable($binary)) {
            return null;
        }

        return 'docling binary not found — `pip install docling` or set KB_OCR_DOCLING_BIN.';
    }

    public function isRemote(): bool
    {
        return false;
    }

    public function fingerprint(): string
    {
        return 'docling;bin='.basename((string) config('kb.ocr.docling.binary', 'docling'));
    }

    /** One bounded process for the whole document. */
    public function maxDurationSeconds(int $pages): int
    {
        return max(1, (int) config('kb.ocr.docling.timeout', 600));
    }

    /** The whole file goes to the engine: nothing caps the pages it will render. */
    public function boundsWorkWithoutPageCount(): bool
    {
        return false;
    }

    public function meteringMode(): OcrMeteringMode
    {
        return OcrMeteringMode::PerPage;
    }

    public function recognise(OcrRequest $request): OcrResult
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new OcrDriverUnavailableException($reason);
        }

        $binary = (string) config('kb.ocr.docling.binary', 'docling');
        $timeout = (int) config('kb.ocr.docling.timeout', 600);

        // Private to the worker's user (0700), like the rasteriser's directory.
        $dir = sys_get_temp_dir().'/kb_docling_'.bin2hex(random_bytes(6));
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Could not create Docling working directory {$dir}.");
        }

        $extension = $request->isPdf() ? 'pdf' : $this->imageExtension($request->effectiveMimeType());
        $input = $dir.'/input.'.$extension;
        if (file_put_contents($input, $request->bytes) === false) {
            $this->removeDirAfterFailure($dir, $e = new \RuntimeException("Could not write Docling input to {$input}."));
            throw $e;
        }

        try {
            $process = new Process([
                $binary, $input,
                '--to', 'md',
                '--image-export-mode', 'referenced',
                '--output', $dir,
                '--ocr',
            ]);
            $process->setTimeout($timeout);
            $this->runBounded($process, 'docling');

            $markdownFile = $dir.'/input.md';
            if (! is_file($markdownFile)) {
                throw new \RuntimeException('Docling produced no Markdown output.');
            }
            $markdown = file_get_contents($markdownFile);
            if ($markdown === false) {
                throw new \RuntimeException("Docling Markdown output could not be read: {$markdownFile}.");
            }

            $result = new OcrResult(
                driver: $this->name(),
                pages: $this->parseMarkdownOutput($markdown, $dir),
                meta: ['engine' => 'docling'],
            );
        } catch (\Throwable $e) {
            $this->removeDirAfterFailure($dir, $e);
            throw $e;
        }
        // The figures' bytes are already in memory: the working tree goes
        // BEFORE the result is returned (a leftover here is document data).
        $this->removeDir($dir);

        return $result;
    }

    /**
     * Run a process and rethrow a BOUNDED failure: `ProcessFailedException`
     * embeds the full stdout/stderr, which for an OCR engine is the
     * recognised page text (PII) — that must never reach the job log or the
     * FlowRun record (SEC-LOG-001).
     */
    private function runBounded(Process $process, string $label): void
    {
        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $stderr = trim(preg_replace('/\s+/', ' ', $process->getErrorOutput()) ?? '');
            throw new \RuntimeException(sprintf(
                '%s exited with code %s%s',
                $label,
                (string) $process->getExitCode(),
                $stderr !== '' ? ': '.mb_substr($stderr, 0, 200) : '',
            ), 0, null);
        }
    }

    /**
     * Docling emits `<!-- page break -->` (or a form feed) between pages and
     * references exported images as `![…](input_artifacts/image_N.png)`. Each
     * referenced file becomes an OcrFigure and the link is rewritten to the
     * canonical `images/fig-{page}-{n}.{ext}` shape.
     *
     * The link target is OCR OUTPUT — attacker-influenced text — so it never
     * decides a filesystem read on its own (SEC-PATH-001): only a bare
     * `input_artifacts/<name>.<png|jpg|jpeg|webp|tif|tiff>` shape is
     * accepted, the resolved path must stay inside the working directory
     * after `realpath()`, and the extension comes from that allow-list.
     * Anything else is left in the Markdown as text.
     *
     * @internal exposed for the unit test; not part of the driver contract.
     *
     * @return list<OcrPage>
     */
    public function parseMarkdownOutput(string $markdown, string $dir): array
    {
        $root = realpath($dir);
        $raw = preg_split('/\n?<!--\s*page\s*break\s*-->\n?|\f/i', $markdown) ?: [$markdown];
        $pages = [];
        foreach (array_values($raw) as $i => $body) {
            $number = $i + 1;
            $figures = [];
            $maxFigureBytes = max(1, (int) config('kb.ocr.max_figure_bytes', 10 * 1024 * 1024));
            $body = (string) preg_replace_callback(
                '/!\[([^\]]*)\]\(([^)]+)\)/',
                function (array $m) use ($root, $number, $maxFigureBytes, &$figures): string {
                    $target = trim($m[2]);
                    if ($root === false || preg_match('#^input_artifacts/([A-Za-z0-9_.-]+)\.(png|jpe?g|webp|tiff?)$#i', $target, $tm) !== 1) {
                        return $m[0];
                    }
                    $file = realpath($root.'/'.$target);
                    if ($file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
                        return $m[0];
                    }
                    $index = count($figures) + 1;
                    $ext = strtolower($tm[2]);
                    // The per-figure cap (KB_OCR_MAX_FIGURE_BYTES) holds for
                    // every driver, local ones included: a figure over it is
                    // never read into memory nor stored — the link is replaced
                    // by a note so the Markdown does not cite a missing file.
                    $size = (int) (filesize($file) ?: 0);
                    if ($size > $maxFigureBytes) {
                        Log::warning('Docling figure omitted: over KB_OCR_MAX_FIGURE_BYTES', ['page' => $number, 'bytes' => $size, 'max' => $maxFigureBytes]);

                        return sprintf('*Figure %d.%d omitted (%d bytes, over KB_OCR_MAX_FIGURE_BYTES)*', $number, $index, $size);
                    }
                    $bytes = file_get_contents($file);
                    if ($bytes === false || $bytes === '') {
                        throw new \RuntimeException("Docling figure could not be read: {$file}.");
                    }
                    $figure = new OcrFigure($number, $index, $bytes, $ext);
                    $figures[] = $figure;

                    return sprintf('![%s](images/%s)', $m[1] !== '' ? $m[1] : "Figure {$number}.{$index}", $figure->fileName());
                },
                $body,
            );
            $pages[] = new OcrPage(number: $number, markdown: trim($body), confidence: null, figures: $figures);
        }

        return $pages;
    }

    private function imageExtension(string $mime): string
    {
        return match (strtolower(trim(explode(';', $mime, 2)[0]))) {
            'image/jpeg' => 'jpg',
            'image/tiff' => 'tiff',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    /**
     * Remove the working tree (source copy, Markdown, figures). A failed
     * removal is never silent (SEC-RETENTION-001): what is left is logged and
     * the call throws, so a job cannot report success while document bytes
     * remain in /tmp. Failure paths use {@see removeDirAfterFailure()}.
     */
    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        $left = [];
        foreach ($items as $item) {
            if (! self::remove($item->getPathname(), $item->isDir())) {
                $left[] = $item->getPathname();
            }
        }
        if ($left === [] && ! self::remove($dir, true)) {
            $left[] = $dir;
        }
        if ($left === []) {
            return;
        }
        Log::error('Docling temp cleanup failed: document bytes remain on disk', ['dir' => $dir, 'left' => $left]);
        throw new \RuntimeException(sprintf('Docling temp cleanup failed, %d item(s) remain under %s.', count($left), $dir));
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

    private function removeDirAfterFailure(string $dir, \Throwable $primary): void
    {
        try {
            $this->removeDir($dir);
        } catch (\Throwable $e) {
            Log::error('Docling temp cleanup failed after a driver error', ['dir' => $dir, 'cleanup_error' => $e->getMessage(), 'primary_error' => $primary->getMessage()]);
        }
    }
}
