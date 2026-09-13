<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\Drivers\Concerns\RasterisesPdf;
use App\Services\Kb\Ocr\Drivers\FakeOcrDriver;
use App\Services\Kb\Ocr\OcrDriverUnavailableException;
use App\Services\Kb\Ocr\OcrLimitExceededException;
use App\Services\Kb\Ocr\OcrRequest;
use Tests\TestCase;

/**
 * ADR 0029 §4 — a local driver may run on a PDF whose page count could not
 * be verified only because its work is bounded by construction: the
 * rasteriser renders at most `KB_OCR_MAX_PAGES` pages whatever the file's
 * object table claims, and no page renders wider than the pixel box — the
 * DPI is derived from the page geometry `pdfinfo` reports BEFORE the render.
 * Poppler is not a test dependency: both binaries are stubs that record
 * their argv; the pdftoppm stub produces one real (1×1) PNG page.
 */
final class RasterisesPdfTest extends TestCase
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
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function test_rasterisation_is_capped_at_the_page_limit_whatever_the_pdf_claims(): void
    {
        config(['kb.ocr.max_pages' => 7]);
        [$pdftoppm, $argv] = $this->pdftoppmStub();
        $pdfinfo = $this->pdfinfoStub("Page    1 size: 612 x 792 pts (letter)\n");

        $raster = $this->driver()->run($this->pdf('x.pdf'), $pdftoppm, 150, $pdfinfo);
        $args = $this->argv($argv);
        $mode = fileperms($raster['dir']) & 0777;
        $this->driver()->clean($raster['dir']);

        $this->assertSame(0700, $mode, 'the working directory is private to the worker user');
        $this->assertSame([1], array_keys($raster['pages']));
        $this->assertContains('-l', $args);
        $this->assertSame('7', $args[array_search('-l', $args, true) + 1]);
        $this->assertSame('1', $args[array_search('-f', $args, true) + 1]);
        $this->assertSame('150', $args[array_search('-r', $args, true) + 1], 'a letter page fits the box at the configured DPI');
        $this->assertNotContains('-W', $args, '-W/-H are crops, never a bound');
        $this->assertNotContains('-H', $args);
    }

    /**
     * ADR 0029 §4 — the pixel box is enforced BEFORE the render: the DPI is
     * lowered so the longest page side reported by pdfinfo fits the box.
     */
    public function test_the_render_dpi_is_lowered_so_the_largest_page_fits_the_pixel_box(): void
    {
        config(['kb.ocr.raster.max_page_px' => 6000]);
        [$pdftoppm, $argv] = $this->pdftoppmStub();
        // 4 000 pt on the long side: 6000 px × 72 / 4000 pt = 108 DPI.
        $pdfinfo = $this->pdfinfoStub("Page    1 size: 612 x 792 pts\nPage    1 rot:  0\nPage    2 size: 4000 x 2000 pts\n");

        $raster = $this->driver()->run($this->pdf('wide.pdf'), $pdftoppm, 200, $pdfinfo);
        $args = $this->argv($argv);
        $this->driver()->clean($raster['dir']);

        $this->assertSame('108', $args[array_search('-r', $args, true) + 1]);
    }

    /**
     * ADR 0029 §4 — a page that cannot fit the box even at the 50-DPI floor is
     * refused before pdftoppm runs; pdfinfo reporting no page size at all is
     * the same refusal (an unbounded render is never attempted).
     */
    public function test_a_page_that_cannot_fit_the_box_at_the_dpi_floor_is_refused_before_rendering(): void
    {
        config(['kb.ocr.raster.max_page_px' => 6000]);
        [$pdftoppm, $argv] = $this->pdftoppmStub();
        // 200 inches = 14 400 pt: 6000 × 72 / 14400 = 30 DPI < 50.
        $pdfinfo = $this->pdfinfoStub("Page size:      14400 x 14400 pts\n");

        try {
            $this->driver()->run($this->pdf('poster.pdf'), $pdftoppm, 150, $pdfinfo);
            $this->fail('a page over the box at the DPI floor must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('rendered_page_too_large', $e->reason);
            $this->assertStringContainsString('poster.pdf', $e->getMessage());
            $this->assertStringContainsString('KB_OCR_RASTER_MAX_PAGE_PX', $e->getMessage());
        }
        $this->assertSame('', trim((string) file_get_contents($argv)), 'pdftoppm never ran');
        $this->assertSame([], glob(sys_get_temp_dir().'/kb_ocr_*/input.pdf') ?: [], 'the working directory is removed on refusal');

        $noSize = $this->pdfinfoStub("Producer:       nothing useful\n");
        try {
            $this->driver()->run($this->pdf('opaque.pdf'), $pdftoppm, 150, $noSize);
            $this->fail('a PDF whose page size cannot be read must not be rendered unbounded');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('rendered_page_too_large', $e->reason);
        }
        $this->assertSame('', trim((string) file_get_contents($argv)), 'pdftoppm never ran');
    }

    public function test_a_missing_pdfinfo_binary_is_an_unavailable_driver_not_an_unbounded_render(): void
    {
        [$pdftoppm, $argv] = $this->pdftoppmStub();

        try {
            $this->driver()->run($this->pdf('x.pdf'), $pdftoppm, 150, '/nonexistent/pdfinfo');
            $this->fail('pdfinfo missing must be reported');
        } catch (OcrDriverUnavailableException $e) {
            $this->assertStringContainsString('KB_OCR_PDFINFO_BIN', $e->getMessage());
        }
        $this->assertSame('', trim((string) file_get_contents($argv)), 'pdftoppm never ran');
        $this->assertSame([], glob(sys_get_temp_dir().'/kb_ocr_*/input.pdf') ?: []);
    }

    /**
     * ADR 0029 §4 — what a page renders to is bounded separately from the
     * file: a rendered page over the byte cap is a deterministic refusal
     * raised before any egress, with the working directory removed.
     */
    public function test_rendered_pages_over_the_byte_cap_are_refused(): void
    {
        config(['kb.ocr.raster.max_page_px' => 4321, 'kb.ocr.raster.max_page_bytes' => 16]);
        // The real 1×1 PNG the stub renders is ~70 bytes — over a 16-byte cap.
        [$pdftoppm, $argv] = $this->pdftoppmStub();
        $pdfinfo = $this->pdfinfoStub("Page    1 size: 612 x 792 pts\n");

        try {
            $this->driver()->run($this->pdf('big.pdf'), $pdftoppm, 150, $pdfinfo);
            $this->fail('a rendered page over the byte cap must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('rendered_page_too_large', $e->reason);
            $this->assertStringContainsString('big.pdf', $e->getMessage());
            $this->assertStringContainsString('KB_OCR_RASTER_MAX_PAGE_BYTES', $e->getMessage());
        }
        $this->assertSame('150', $this->argv($argv)[array_search('-r', $this->argv($argv), true) + 1]);
        $this->assertSame([], glob(sys_get_temp_dir().'/kb_ocr_*/page-1.png') ?: [], 'the working directory is removed on refusal');
    }

    /**
     * Defence in depth: the PNG that was actually produced is re-measured, so
     * a renderer that ignored the DPI still cannot hand a page over the box
     * to the engine or the provider.
     */
    public function test_a_produced_page_over_the_pixel_box_is_refused_even_if_the_renderer_ignored_the_dpi(): void
    {
        config(['kb.ocr.raster.max_page_px' => 500, 'kb.ocr.raster.max_page_bytes' => 10485760]);
        // A PNG header declaring 501 × 1 px (getimagesize reads IHDR only).
        [$pdftoppm] = $this->pdftoppmStub(self::pngHeader(501, 1));
        $pdfinfo = $this->pdfinfoStub("Page    1 size: 100 x 100 pts\n");

        try {
            $this->driver()->run($this->pdf('lying.pdf'), $pdftoppm, 72, $pdfinfo);
            $this->fail('a produced page over the pixel box must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('rendered_page_too_large', $e->reason);
            $this->assertStringContainsString('501×1 px', $e->getMessage());
        }
        $this->assertSame([], glob(sys_get_temp_dir().'/kb_ocr_*/page-1.png') ?: []);
    }

    /**
     * ADR 0029 §4 — an image IS the page: the same pixel and byte bounds
     * apply to it before an engine decodes it or a provider receives it (a
     * highly compressed file with huge dimensions is a decode bomb whatever
     * the source byte cap said).
     */
    public function test_a_direct_image_over_the_pixel_box_or_the_byte_cap_is_refused_too(): void
    {
        config(['kb.ocr.raster.max_page_px' => 500, 'kb.ocr.raster.max_page_bytes' => 10485760]);
        [$pdftoppm] = $this->pdftoppmStub();
        try {
            $this->driver()->run(new OcrRequest(bytes: self::pngHeader(9000, 9000), mimeType: 'image/png', filename: 'bomb.png', options: []), $pdftoppm, 150, '/nonexistent/pdfinfo');
            $this->fail('an image over the pixel box must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('rendered_page_too_large', $e->reason);
            $this->assertStringContainsString('9000×9000 px', $e->getMessage());
        }
        $this->assertSame([], glob(sys_get_temp_dir().'/kb_ocr_*/input.img') ?: [], 'the working directory is removed on refusal');

        config(['kb.ocr.raster.max_page_px' => 6000, 'kb.ocr.raster.max_page_bytes' => 16]);
        try {
            $this->driver()->run(new OcrRequest(bytes: (string) base64_decode(FakeOcrDriver::PNG_1X1, true), mimeType: 'image/png', filename: 'big.png', options: []), $pdftoppm, 150, '/nonexistent/pdfinfo');
            $this->fail('an image over the byte cap must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('rendered_page_too_large', $e->reason);
            $this->assertStringContainsString('KB_OCR_RASTER_MAX_PAGE_BYTES', $e->getMessage());
        }

        // Within both bounds the image is the single page, no pdftoppm/pdfinfo involved.
        config(['kb.ocr.raster.max_page_bytes' => 10485760]);
        $raster = $this->driver()->run(new OcrRequest(bytes: (string) base64_decode(FakeOcrDriver::PNG_1X1, true), mimeType: 'image/png', filename: 'ok.png', options: []), $pdftoppm, 150, '/nonexistent/pdfinfo');
        $this->assertSame([1], array_keys($raster['pages']));
        $this->driver()->clean($raster['dir']);
    }

    /**
     * Defence in depth behind `OcrDriver::acceptsMultiFrameImages()`: the
     * rasteriser hands an image to the engine as ONE page, so a multi-frame
     * TIFF that reached it would be transcribed for its first frame only —
     * refused with the same reason the service uses, working dir removed.
     */
    public function test_a_multi_frame_tiff_never_becomes_a_single_page(): void
    {
        $header = 'II'.pack('v', 42).pack('V', 8);
        $ifds = '';
        for ($i = 0; $i < 2; $i++) {
            $offset = 8 + strlen($ifds);
            $next = $i === 1 ? 0 : $offset + 2 + 12 + 4;
            $ifds .= pack('v', 1).pack('v', 256).pack('v', 3).pack('V', 1).pack('V', 1).pack('V', $next);
        }
        [$pdftoppm] = $this->pdftoppmStub();

        try {
            $this->driver()->run(new OcrRequest(bytes: $header.$ifds, mimeType: 'image/tiff', filename: 'two.tiff', options: []), $pdftoppm, 150, '/nonexistent/pdfinfo');
            $this->fail('a multi-frame TIFF must be refused by the rasteriser');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('multi_frame_image', $e->reason);
            $this->assertStringContainsString('2-frame TIFF', $e->getMessage());
        }
        $this->assertSame([], glob(sys_get_temp_dir().'/kb_ocr_*/input.img') ?: [], 'the working directory is removed on refusal');
    }

    public function test_an_unreadable_produced_page_is_an_error_not_a_page(): void
    {
        [$pdftoppm] = $this->pdftoppmStub('not a png');
        $pdfinfo = $this->pdfinfoStub("Page    1 size: 100 x 100 pts\n");

        try {
            $this->driver()->run($this->pdf('broken.pdf'), $pdftoppm, 72, $pdfinfo);
            $this->fail('an unreadable page image must not be handed on');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unreadable page image', $e->getMessage());
        }
        $this->assertSame([], glob(sys_get_temp_dir().'/kb_ocr_*/page-1.png') ?: []);
    }

    private function driver(): object
    {
        return new class
        {
            use RasterisesPdf;

            /** @return array{dir: string, pages: array<int, string>} */
            public function run(OcrRequest $request, string $pdftoppm, int $dpi, string $pdfinfo): array
            {
                return $this->rasterise($request, $pdftoppm, $dpi, 30, $pdfinfo);
            }

            public function clean(string $dir): void
            {
                $this->cleanup($dir);
            }
        };
    }

    private function pdf(string $name): OcrRequest
    {
        return new OcrRequest(bytes: '%PDF-1.4 not really a pdf', mimeType: 'application/pdf', filename: $name, options: []);
    }

    /**
     * A pdftoppm stub that records its argv and writes `$pageBytes` as page 1.
     *
     * @return array{0: string, 1: string} binary path, argv record path
     */
    private function pdftoppmStub(?string $pageBytes = null): array
    {
        $pageBytes ??= (string) base64_decode(FakeOcrDriver::PNG_1X1, true);
        $page = $this->temp('pdftoppm_page_');
        file_put_contents($page, $pageBytes);
        $argv = $this->temp('pdftoppm_argv_');
        $stub = $this->temp('pdftoppm_stub_');
        file_put_contents($stub, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($argv)."\nout=\"\$(eval echo \\\${\$#})\"\ncat ".escapeshellarg($page)." > \"\${out}-1.png\"\n");
        chmod($stub, 0755);

        return [$stub, $argv];
    }

    /** A pdfinfo stub that prints `$report`. */
    private function pdfinfoStub(string $report): string
    {
        $out = $this->temp('pdfinfo_report_');
        file_put_contents($out, $report);
        $stub = $this->temp('pdfinfo_stub_');
        file_put_contents($stub, "#!/bin/sh\ncat ".escapeshellarg($out)."\n");
        chmod($stub, 0755);

        return $stub;
    }

    /** @return list<string> */
    private function argv(string $argvFile): array
    {
        return explode("\n", trim((string) file_get_contents($argvFile)));
    }

    private function temp(string $prefix): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), $prefix);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** PNG signature + IHDR chunk only: enough for getimagesize() to report the dimensions. */
    private static function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('NN', $width, $height).chr(8).chr(2).chr(0).chr(0).chr(0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }
}
