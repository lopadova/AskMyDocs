<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

/**
 * A figure the engine cut out of a page. `bytes` is the encoded image
 * (PNG unless `extension` says otherwise); the converter writes it to the
 * kb disk and rewrites the Markdown reference to `images/fig-{page}-{n}.{ext}`.
 */
final readonly class OcrFigure
{
    /**
     * `SourceType::imageExtensionFromMime()`'s own range — the only
     * extensions a raster figure can legitimately carry. `pdf`/`svg`/etc
     * are not figures; a driver never rasterises a figure into one of
     * those.
     */
    private const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'tiff'];

    public string $extension;

    public function __construct(
        public int $page,
        public int $index,
        public string $bytes,
        string $extension = 'png',
        /** The placeholder the driver put in the page Markdown, if any. */
        public ?string $placeholder = null,
    ) {
        // `fileName()` interpolates `extension` straight into a storage
        // key (`OcrFigureStore::store()`). The built-in drivers already
        // allow-list what they pass in, but this value object is the
        // shared contract a future/custom driver — or a corrupted-but-
        // parseable recorded run (`OcrService::reusableResult()`, which
        // derives it from a persisted path via `pathinfo()`) — also goes
        // through; enforcing the allow-list HERE closes it for every
        // caller at once instead of at each call site. Case-insensitive
        // (drivers/`pathinfo()` may hand back either case); an unknown or
        // path-bearing value (`../result.json`) falls back to the same
        // default `SourceType::imageExtensionFromMime()` uses for an
        // unrecognised MIME, never propagates raw.
        $normalised = strtolower($extension);
        $this->extension = in_array($normalised, self::ALLOWED_EXTENSIONS, true) ? $normalised : 'png';
    }

    public function fileName(): string
    {
        return sprintf('fig-%d-%d.%s', $this->page, $this->index, $this->extension);
    }
}
