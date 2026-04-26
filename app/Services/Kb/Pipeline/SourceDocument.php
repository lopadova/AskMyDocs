<?php

declare(strict_types=1);

namespace App\Services\Kb\Pipeline;

/**
 * Immutable input to the v3 ingestion pipeline.
 *
 * Carries the raw bytes of a document plus its provenance (path, MIME type,
 * external URL/ID for connector-sourced docs, free-form metadata).
 *
 * Pipeline flow: SourceDocument -> Converter -> ConvertedDocument -> Chunker -> ChunkDraft[].
 */
final readonly class SourceDocument
{
    /**
     * @param  array<string, mixed>  $metadata  Free-form key-value bag (language, owner, custom labels, ...).
     */
    public function __construct(
        public string $sourcePath,
        public string $mimeType,
        public string $bytes,
        public ?string $externalUrl,
        public ?string $externalId,
        public string $connectorType,
        public array $metadata,
    ) {}
}
