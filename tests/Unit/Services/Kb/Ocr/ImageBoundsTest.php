<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\Drivers\FakeOcrDriver;
use App\Services\Kb\Ocr\ImageBounds;
use App\Services\Kb\Ocr\OcrLimitExceededException;
use Tests\TestCase;

/**
 * ADR 0029 §4 — the bounds a source image must fit when it is decoded or
 * posted as is: the estimate's reason and the driver's refusal are ONE
 * decision, so what the modal says before commit is what the run does.
 */
final class ImageBoundsTest extends TestCase
{
    private function png(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00".pack('N', 0);
    }

    public function test_an_image_inside_both_bounds_has_no_refusal_reason(): void
    {
        $this->assertNull(ImageBounds::refusalReason((string) base64_decode(FakeOcrDriver::PNG_1X1, true)));
        ImageBounds::assertWithinRasterBounds((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'ok.png');
        $this->assertTrue(true, 'no exception');
    }

    public function test_the_pixel_box_and_the_page_byte_cap_share_one_reason_and_the_estimate_matches_the_driver(): void
    {
        config(['kb.ocr.raster.max_page_px' => 500]);
        $wide = $this->png(900, 10);
        $this->assertSame('rendered_page_too_large', ImageBounds::refusalReason($wide));
        try {
            ImageBounds::assertWithinRasterBounds($wide, 'wide.png');
            $this->fail('over the pixel box');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('rendered_page_too_large', $e->reason);
        }

        config(['kb.ocr.raster.max_page_px' => 6000, 'kb.ocr.raster.max_page_bytes' => 16]);
        $this->assertSame('rendered_page_too_large', ImageBounds::refusalReason($wide));
        try {
            ImageBounds::assertWithinRasterBounds($wide, 'heavy.png');
            $this->fail('over the page byte cap');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('rendered_page_too_large', $e->reason);
            $this->assertStringContainsString('KB_OCR_RASTER_MAX_PAGE_BYTES (16)', $e->getMessage());
        }
    }

    /**
     * Bytes PHP cannot measure are not the estimate's call (the sniffer and
     * the page/frame gates judge them); a driver that must decode them as is
     * still refuses to send what it cannot bound (R14).
     */
    public function test_bytes_that_cannot_be_measured_are_left_to_the_run_but_never_sent_unbounded(): void
    {
        $this->assertNull(ImageBounds::refusalReason('%PDF-1.4 not a raster'));
        $this->expectException(\RuntimeException::class);
        ImageBounds::assertWithinRasterBounds('%PDF-1.4 not a raster', 'not.png');
    }

    /** The caps have floors (500 px, 1 byte): a zero or negative setting cannot switch the bound off. */
    public function test_the_caps_never_collapse_to_zero(): void
    {
        config(['kb.ocr.raster.max_page_px' => 0, 'kb.ocr.raster.max_page_bytes' => 0]);
        $this->assertSame(500, ImageBounds::maxPagePx());
        $this->assertSame(1, ImageBounds::maxPageBytes());
    }
}
