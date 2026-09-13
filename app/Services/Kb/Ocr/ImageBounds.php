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
     * @throws OcrLimitExceededException  over the pixel box (`rendered_page_too_large`)
     * @throws \RuntimeException           when the bytes cannot be measured
     */
    public static function assertWithinPixelBox(string $bytes, string $filename): void
    {
        $maxPx = max(500, (int) config('kb.ocr.raster.max_page_px', 6000));
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
}
