<?php

declare(strict_types=1);

namespace App\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Ocr\OcrService;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\Kb\SourceType;

/**
 * v8.36 / ADR 0029 — image → markdown through OCR.
 *
 * Registered BEFORE PdfConverter in `config/kb-pipeline.php`. Claims every
 * MIME in {@see SourceType::imageMimes()} — and only when `kb.ocr.enabled`
 * is true: with the flag off `supports()` is false for every MIME, so the
 * registry falls through exactly as in v8.35 (an image reaches "No converter
 * registered", which the entry points already turn into the same 422).
 *
 * Scanned PDFs are NOT claimed here. The registry resolves a converter by
 * MIME alone, so the text-layer decision cannot be made in `supports()`;
 * it lives as a fallback inside {@see PdfConverter}, which delegates to the
 * same {@see OcrService} — one core, two entry MIMEs.
 *
 * Output keeps the `# {filename}` + `## Page N` shape so PdfPageChunker
 * chunks it unchanged (one page per image, one per TIFF frame when the
 * driver splits them).
 *
 * Side effect, deliberately: figures are written to the kb disk at
 * conversion time (the Flow persists step outputs to the database, and
 * binary blobs do not belong there). Every write is checked (R4).
 */
final class OcrConverter implements ConverterInterface
{
    public function __construct(private readonly OcrService $ocr) {}

    public function name(): string
    {
        return 'ocr-converter';
    }

    public function supports(string $mimeType): bool
    {
        if (! $this->ocr->enabled()) {
            return false;
        }

        $normalized = strtolower(trim(explode(';', $mimeType, 2)[0]));

        return in_array($normalized, SourceType::imageMimes(), true);
    }

    public function convert(SourceDocument $doc): ConvertedDocument
    {
        return $this->ocr->convert($doc, $this->name(), reason: 'image');
    }
}
