<?php

declare(strict_types=1);

namespace App\Services\Kb\Contracts;

use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;

/**
 * Contract for v3 ingestion-pipeline converters.
 *
 * A converter accepts a SourceDocument (raw bytes + provenance) and produces a
 * ConvertedDocument (markdown text + extraction metadata) that the chunker can
 * then split into ChunkDraft[].
 *
 * Implementations MUST be stateless and side-effect-free; the pipeline
 * resolves a single instance per process via the Laravel container (the
 * concrete registry/resolver lands in T1.4).
 *
 * Documented exception (v8.36, ADR 0029): the OCR path — `OcrConverter`, and
 * `PdfConverter` when it falls back to OCR — writes the figures a driver
 * extracts beside the source (`{source}.ocr/{run}/images/`) at conversion
 * time, because the Flow persists step outputs to the database and binary
 * blobs cannot travel in `ConvertedDocument::mediaItems`. The write is
 * content-addressed and idempotent (same bytes + engine → same run directory,
 * a second run is a no-op), the run is immutable, it follows the source's
 * lifecycle (`DocumentDeleter`), and it NEVER happens under a dry run:
 * `ParseMarkdownStep` marks the SourceDocument (`metadata.dry_run`) and the
 * OCR core then neither calls a driver, nor writes, nor meters.
 */
interface ConverterInterface
{
    /**
     * Stable, lower-kebab-case identifier (e.g. 'pdf-converter', 'markdown-passthrough').
     */
    public function name(): string;

    /**
     * Returns true if this converter can handle the given MIME type.
     *
     * Non-overlap requirement: no two registered converters may both return
     * true for the same MIME. The pipeline registry (introduced in T1.4)
     * uses first-match-wins resolution; ambiguity is a configuration bug.
     * See plan §1 (Dependency DAG) and the Pilastro A architecture note.
     */
    public function supports(string $mimeType): bool;

    /**
     * Convert the source document to a markdown-normalised representation.
     *
     * @throws \RuntimeException When extraction fails irrecoverably.
     */
    public function convert(SourceDocument $doc): ConvertedDocument;
}
