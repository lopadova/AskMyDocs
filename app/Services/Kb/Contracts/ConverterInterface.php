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
 * Implementations MUST be stateless and side-effect-free; the PipelineRegistry
 * resolves a single instance per process via the Laravel container.
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
     * Per R23 (pipeline-supports-mutex), no two registered converters may both
     * return true for the same MIME — the registry uses first-match-wins.
     */
    public function supports(string $mimeType): bool;

    /**
     * Convert the source document to a markdown-normalised representation.
     *
     * @throws \RuntimeException When extraction fails irrecoverably.
     */
    public function convert(SourceDocument $doc): ConvertedDocument;
}
