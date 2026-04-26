<?php

declare(strict_types=1);

namespace App\Services\Kb\Pipeline;

/**
 * Immutable input to the v3 ingestion pipeline.
 *
 * Carries the raw bytes of a document plus its provenance (path, MIME type,
 * external URL/ID for connector-sourced docs, free-form metadata).
 *
 * Pipeline flow:
 *   SourceDocument -> Converter -> ConvertedDocument -> Chunker -> list<ChunkDraft>
 *
 * Per R1 (kb-path-normalization), `$sourcePath` MUST be pre-normalised by
 * `App\Support\KbPath::normalize()` before constructing this DTO. The
 * pipeline does NOT re-normalise — accepting the path verbatim avoids
 * double-collapse and keeps deletion paths idempotent. Callers that
 * receive raw paths from HTTP/CLI/connectors are responsible for the
 * normalisation step at the boundary, exactly as `DocumentIngestor` and
 * `KbIngestController` already do today for markdown ingest.
 */
final readonly class SourceDocument
{
    /**
     * @param  string                $sourcePath  KB-relative path. MUST be pre-normalised via App\Support\KbPath::normalize().
     * @param  array<string, mixed>  $metadata    Free-form key-value bag (language, owner, custom labels, ...).
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
