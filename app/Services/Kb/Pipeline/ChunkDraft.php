<?php

declare(strict_types=1);

namespace App\Services\Kb\Pipeline;

/**
 * Immutable draft chunk produced by a Chunker, before embedding and persistence.
 *
 * Carries the chunk text, a deterministic order index within its parent document,
 * the heading-path breadcrumb that locates the chunk inside the source structure,
 * and a metadata bag the persistence layer (DocumentIngestor) consumes.
 */
final readonly class ChunkDraft
{
    /**
     * @param  array<string, mixed>  $metadata  Free-form metadata (filename, strategy, wikilinks, ...).
     */
    public function __construct(
        public string $text,
        public int $order,
        public string $headingPath,
        public array $metadata,
    ) {}
}
