<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

/**
 * The pixel box every raster an engine decodes or a provider receives has
 * to fit (`KB_OCR_RASTER_MAX_PAGE_PX`, ADR 0029 §4). The rasterising drivers
 * measure each rendered page; a driver that posts a source image AS IS
 * measures the image here, before the request is built — a highly
 * compressed file inside the source byte cap can still declare dimensions
 * whose decoded allocation is a bomb for whoever decodes it.
 */
final class ImageBounds
{
    /**
     * @return array{0: int, 1: int}|null  width and height, null when the bytes are not a decodable image
     */
    public static function measure(string $bytes): ?array
    {
        try {
            $info = getimagesizefromstring($bytes);
        } catch (\Throwable) {
            return null;
        }
        if ($info === false || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * Why a source image posted or decoded AS IS would be refused by the
     * raster bounds — `rendered_page_too_large` over the pixel box or the
     * page byte cap — or null when it fits. The SAME decision the drivers
     * take ({@see assertWithinRasterBounds()}), so the estimate can give it
     * before commit (R14). Bytes the sniffer accepted but PHP cannot
     * measure are not judged here: the page-count and frame gates, and the
     * driver's own decode, decide what happens to them at run time.
     */
    public static function refusalReason(string $bytes): ?string
    {
        if (strlen($bytes) > self::maxPageBytes()) {
            return 'rendered_page_too_large';
        }
        $dimensions = self::measure($bytes);
        if ($dimensions === null) {
            return null;
        }
        [$width, $height] = $dimensions;

        return $width <= self::maxPagePx() && $height <= self::maxPagePx() ? null : 'rendered_page_too_large';
    }

    /**
     * The bounds every rendered page obeys, applied to a source image that
     * is decoded or posted as is: the pixel box AND the page byte cap
     * (`KB_OCR_RASTER_MAX_PAGE_BYTES` — what one page may weigh, whatever
     * the source file cap admitted).
     *
     * @throws OcrLimitExceededException  over either bound (`rendered_page_too_large`)
     * @throws \RuntimeException           when the bytes cannot be measured
     */
    public static function assertWithinRasterBounds(string $bytes, string $filename): void
    {
        $maxBytes = self::maxPageBytes();
        if (strlen($bytes) > $maxBytes) {
            throw new OcrLimitExceededException(sprintf(
                'OCR refused for "%s": the image is %d bytes, over KB_OCR_RASTER_MAX_PAGE_BYTES (%d).',
                $filename,
                strlen($bytes),
                $maxBytes,
            ), 'rendered_page_too_large');
        }
        self::assertWithinPixelBox($bytes, $filename);
    }

    /**
     * @throws OcrLimitExceededException  over the pixel box (`rendered_page_too_large`)
     * @throws \RuntimeException           when the bytes cannot be measured
     */
    public static function assertWithinPixelBox(string $bytes, string $filename): void
    {
        $maxPx = self::maxPagePx();
        $dimensions = self::measure($bytes);
        if ($dimensions === null) {
            throw new \RuntimeException(sprintf('Image "%s" could not be measured; nothing is sent.', $filename));
        }
        [$width, $height] = $dimensions;
        if ($width <= $maxPx && $height <= $maxPx) {
            return;
        }
        throw new OcrLimitExceededException(sprintf(
            'OCR refused for "%s": the image is %d×%d px, over KB_OCR_RASTER_MAX_PAGE_PX (%d).',
            $filename,
            $width,
            $height,
            $maxPx,
        ), 'rendered_page_too_large');
    }

    public static function maxPagePx(): int
    {
        return max(500, (int) config('kb.ocr.raster.max_page_px', 6000));
    }

    public static function maxPageBytes(): int
    {
        return max(1, (int) config('kb.ocr.raster.max_page_bytes', 10485760));
    }
}
