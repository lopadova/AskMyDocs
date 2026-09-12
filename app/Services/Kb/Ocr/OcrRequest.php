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
}
