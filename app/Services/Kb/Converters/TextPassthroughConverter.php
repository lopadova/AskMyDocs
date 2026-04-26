<?php

declare(strict_types=1);

namespace App\Services\Kb\Converters;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;

/**
 * Plain-text → minimal-markdown converter.
 *
 * Wraps the source body in a synthetic `# {basename}` H1 header so the
 * downstream {@see \App\Services\Kb\MarkdownChunker} can resolve via
 * `section_aware` strategy and emit a single chunk with a meaningful
 * `heading_path` breadcrumb (instead of falling back to `paragraph_split`,
 * which produces no breadcrumb at all).
 *
 * Pattern reusable for any future binary-prose converter that has no
 * native heading structure.
 */
final class TextPassthroughConverter implements ConverterInterface
{
    public function name(): string
    {
        return 'text-passthrough';
    }

    public function supports(string $mimeType): bool
    {
        return $mimeType === 'text/plain';
    }

    public function convert(SourceDocument $doc): ConvertedDocument
    {
        $filename = basename($doc->sourcePath);
        // Truly-empty / whitespace-only sources stay empty so MarkdownChunker
        // returns []. Without the guard a heading-only `# {filename}` body
        // would be treated as `section_aware` and produce a useless one-chunk
        // embedding of the filename alone, polluting the vector index.
        $markdown = trim($doc->bytes) === ''
            ? ''
            : "# {$filename}\n\n" . $doc->bytes;

        return new ConvertedDocument(
            markdown: $markdown,
            mediaItems: [],
            extractionMeta: [
                'converter' => $this->name(),
                'duration_ms' => 0,
                'source_path' => $doc->sourcePath,
                'filename' => $filename,
            ],
            sourceMimeType: $doc->mimeType,
        );
    }
}
