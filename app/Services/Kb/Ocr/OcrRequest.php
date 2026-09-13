<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

/**
 * Input of an OCR run: the raw bytes plus what the driver needs to name
 * its output. `filename` is the basename of the source path (used for the
 * `# {filename}` H1 and for logging), never a filesystem location.
 */
final readonly class OcrRequest
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $filename,
        /** @var array<string, mixed> free-form hints (language, dpi…) */
        public array $options = [],
    ) {}

    public function isPdf(): bool
    {
        return strtolower(trim(explode(';', $this->mimeType, 2)[0])) === 'application/pdf';
    }

    /**
     * The MIME the bytes actually are. Callers carry the FAMILY MIME
     * (`image/png` for every raster — uploads, the folder walker and
     * `knowledge_documents` all store `SourceType::IMAGE->toMime()`), so a
     * driver that builds a data URL or picks an input format from the MIME
     * must read the magic bytes, not the label. Falls back to the declared
     * MIME when the bytes match no known signature.
     */
    public function effectiveMimeType(): string
    {
        $head = substr($this->bytes, 0, 12);
        if (str_starts_with($head, '%PDF-')) {
            return 'application/pdf';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "II*\0") || str_starts_with($head, "MM\0*")) {
            return 'image/tiff';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return strtolower(trim(explode(';', $this->mimeType, 2)[0]));
    }
}
