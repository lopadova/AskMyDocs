<?php

declare(strict_types=1);

namespace App\Services\Kb\Pipeline;

/**
 * Immutable output of a Converter run.
 *
 * Holds the markdown-normalised representation of the source document together
 * with any extracted media references and converter-specific extraction
 * metadata (page count, duration, fallback strategy used, ...).
 */
final readonly class ConvertedDocument
{
    /**
     * @param  array<int, array<string, mixed>>  $mediaItems      Optional list of extracted media descriptors (image refs, embedded files).
     * @param  array<string, mixed>              $extractionMeta  Free-form metadata produced by the converter.
     */
    public function __construct(
        public string $markdown,
        public array $mediaItems,
        public array $extractionMeta,
        public string $sourceMimeType,
    ) {}
}
