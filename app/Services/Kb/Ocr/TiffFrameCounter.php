<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

/**
 * Counts the frames (pages) of a TIFF by walking its IFD chain — no image
 * decoding, bounded by `MAX_FRAMES`. Used by the pre-egress page cap
 * (ADR 0029 §4): a multi-page TIFF is N pages of spend, not one image.
 * Returns 1 for anything that is not a well-formed TIFF header (the driver
 * will fail loudly on it anyway).
 */
final class TiffFrameCounter
{
    public const MAX_FRAMES = 10000;

    public static function count(string $bytes): int
    {
        if (strlen($bytes) < 8) {
            return 1;
        }
        $order = substr($bytes, 0, 2);
        if ($order === 'II') {
            $u16 = 'v';
            $u32 = 'V';
        } elseif ($order === 'MM') {
            $u16 = 'n';
            $u32 = 'N';
        } else {
            return 1;
        }
        if (unpack($u16, substr($bytes, 2, 2))[1] !== 42) {
            return 1; // BigTIFF (43) and garbage: count as one, the driver decides
        }

        $length = strlen($bytes);
        $offset = unpack($u32, substr($bytes, 4, 4))[1];
        $frames = 0;
        $seen = [];
        while ($offset >= 8 && $offset + 2 <= $length && $frames < self::MAX_FRAMES) {
            if (isset($seen[$offset])) {
                break; // cyclic chain: malformed
            }
            $seen[$offset] = true;
            $entries = unpack($u16, substr($bytes, $offset, 2))[1];
            $next = $offset + 2 + $entries * 12;
            if ($next + 4 > $length) {
                $frames++;
                break;
            }
            $frames++;
            $offset = unpack($u32, substr($bytes, $next, 4))[1];
        }

        return max(1, $frames);
    }
}
