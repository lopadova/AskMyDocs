<?php

declare(strict_types=1);

namespace App\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;

/**
 * Zero-cost converter for sources that are already markdown.
 *
 * Returns the source bytes verbatim and populates `extractionMeta.filename`
 * (the basename of the source path) so {@see \App\Services\Kb\MarkdownChunker}
 * can read it through the v3 ChunkerInterface contract without a side-channel.
 */
final class MarkdownPassthroughConverter implements ConverterInterface
{
    private const SUPPORTED_MIME_TYPES = ['text/markdown', 'text/x-markdown'];

    public function name(): string
    {
        return 'markdown-passthrough';
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
            ],
            sourceMimeType: $doc->mimeType,
        );
    }
}
