<?php

declare(strict_types=1);

namespace App\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;

/**
 * Zero-cost converter for v4.5/W5.5 connector-produced markdown.
 *
 * Six connectors (Notion, Confluence, Evernote, Fabric, Google Drive,
 * OneDrive) already render their source to markdown before handing it
 * to the ingestion pipeline. This converter accepts the synthetic
 * vendor MIME tokens declared in `config/kb-pipeline.php` and passes
 * the markdown bytes through verbatim — the source-aware chunkers
 * downstream do all the structural work.
 *
 * Sits in the converter registry chain so the registry's
 * first-match-wins dispatch can route each vendor MIME to the right
 * chunker without inventing per-vendor converter classes for what is
 * structurally a passthrough.
 */
final class VendorMarkdownPassthroughConverter implements ConverterInterface
{
    private const SUPPORTED_MIME_TYPES = [
        'application/vnd.notion.page+json',
        'application/vnd.notion.note+json',
        'application/vnd.confluence.page+json',
        'application/vnd.evernote.note+xml',
        'application/vnd.fabric.note+json',
        'application/vnd.google-apps.document',
        'application/vnd.google-apps.spreadsheet',
        'application/vnd.google-apps.presentation',
        'application/vnd.onedrive.office+json',
    ];

    public function name(): string
    {
        return 'vendor-markdown-passthrough';
    }

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_MIME_TYPES, true);
    }

    public function convert(SourceDocument $doc): ConvertedDocument
    {
        return new ConvertedDocument(
            markdown: $doc->bytes,
            mediaItems: [],
            extractionMeta: [
                'converter' => $this->name(),
                'duration_ms' => 0,
                'source_path' => $doc->sourcePath,
                'filename' => basename($doc->sourcePath),
                'vendor_mime' => $doc->mimeType,
            ],
            sourceMimeType: $doc->mimeType,
        );
    }
}
