<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\Drivers\Concerns\RasterisesPdf;
use App\Services\Kb\Ocr\OcrLimitExceededException;
use App\Services\Kb\Ocr\OcrRequest;
use Tests\TestCase;

/**
 * ADR 0029 §4 — a local driver may run on a PDF whose page count could not
 * be verified only because its work is bounded by construction: the
 * rasteriser renders at most `KB_OCR_MAX_PAGES` pages whatever the file's
 * object table claims. Poppler is not a test dependency: the binary is a
 * stub that records its argv and produces one page.
 */
final class RasterisesPdfTest extends TestCase
{
    public function test_rasterisation_is_capped_at_the_page_limit_whatever_the_pdf_claims(): void
    {
        config(['kb.ocr.max_pages' => 7]);
        $argv = tempnam(sys_get_temp_dir(), 'pdftoppm_argv_');
        $stub = tempnam(sys_get_temp_dir(), 'pdftoppm_stub_');
        file_put_contents($stub, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($argv)."\nout=\"\$(eval echo \\\${\$#})\"\nprintf 'png' > \"\${out}-1.png\"\n");
        chmod($stub, 0755);

        $driver = new class
        {
            use RasterisesPdf;

            /** @return array{dir: string, pages: array<int, string>} */
            public function run(OcrRequest $request, string $binary): array
            {
                return $this->rasterise($request, $binary, 150, 30);
            }

            public function clean(string $dir): void
            {
                $this->cleanup($dir);
            }
        };

        try {
            $raster = $driver->run(new OcrRequest(bytes: '%PDF-1.4 not really a pdf', mimeType: 'application/pdf', filename: 'x.pdf', options: []), $stub);
            $args = explode("\n", trim((string) file_get_contents($argv)));
            $driver->clean($raster['dir']);
        } finally {
            unlink($stub);
            unlink($argv);
        }

        $this->assertSame([1], array_keys($raster['pages']));
        $this->assertContains('-l', $args);
        $this->assertSame('7', $args[array_search('-l', $args, true) + 1]);
        $this->assertSame('1', $args[array_search('-f', $args, true) + 1]);
    }

    /**
     * ADR 0029 §4 — the source byte cap bounds the FILE; what a page renders
     * to is bounded separately: `-W/-H` clip the rendered area to the pixel
     * box, and a rendered page over the byte cap is a deterministic refusal
     * raised before any egress, with the working directory removed.
     */
    public function test_rendered_pages_are_clipped_to_the_pixel_box_and_refused_over_the_byte_cap(): void
    {
        config(['kb.ocr.raster.max_page_px' => 4321, 'kb.ocr.raster.max_page_bytes' => 16]);
        $argv = tempnam(sys_get_temp_dir(), 'pdftoppm_argv_');
        $stub = tempnam(sys_get_temp_dir(), 'pdftoppm_stub_');
        // The stub renders one "page" of 32 bytes — twice the configured cap.
        file_put_contents($stub, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($argv)."\nout=\"\$(eval echo \\\${\$#})\"\nprintf '%032d' 0 > \"\${out}-1.png\"\n");
        chmod($stub, 0755);

        $driver = new class
        {
            use RasterisesPdf;

            /** @return array{dir: string, pages: array<int, string>} */
            public function run(OcrRequest $request, string $binary): array
            {
                return $this->rasterise($request, $binary, 150, 30);
            }
        };

        try {
            $driver->run(new OcrRequest(bytes: '%PDF-1.4 not really a pdf', mimeType: 'application/pdf', filename: 'big.pdf', options: []), $stub);
            $this->fail('a rendered page over the byte cap must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('rendered_page_too_large', $e->reason);
            $this->assertStringContainsString('big.pdf', $e->getMessage());
            $this->assertStringContainsString('KB_OCR_RASTER_MAX_PAGE_BYTES', $e->getMessage());
            $args = explode("\n", trim((string) file_get_contents($argv)));
        } finally {
            unlink($stub);
            unlink($argv);
        }

        $this->assertSame('4321', $args[array_search('-W', $args, true) + 1]);
        $this->assertSame('4321', $args[array_search('-H', $args, true) + 1]);
        $this->assertSame([], glob(sys_get_temp_dir().'/kb_ocr_*/page-1.png') ?: [], 'the working directory is removed on refusal');
    }
}
