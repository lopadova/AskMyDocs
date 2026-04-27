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
 * Per R1 (kb-path-normalization), `$sourcePath` is normalised inside
 * `App\Services\Kb\DocumentIngestor::ingest()` via
 * `App\Support\KbPath::normalize()` as a safety net — callers may pass a
 * raw KB-relative path and it WILL get normalised before persistence.
 * Idempotent normalisation means the same DTO can be constructed with the
 * pre- or post-normalised form and produce identical persistence keys.
 * Callers that receive raw paths from HTTP/CLI/connectors are still
 * encouraged to normalise at the boundary for consistency and earlier
 * validation feedback (immediate 422 vs deep ingestion failure), but this
 * DTO does NOT require a pre-normalised value.
 */
final readonly class SourceDocument
{
    /**
     * @param  string                $sourcePath  KB-relative path. `DocumentIngestor::ingest()`
     *                                            normalises it via App\Support\KbPath::normalize();
     *                                            callers may also normalise earlier at the boundary
     *                                            for consistency and early validation.
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
