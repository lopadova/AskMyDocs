<?php

declare(strict_types=1);

namespace App\Services\Kb\Contracts;

use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;

/**
 * Contract for v3 ingestion-pipeline chunkers.
 *
 * A chunker accepts a ConvertedDocument and produces an ordered array of
 * ChunkDraft instances ready for embedding and persistence.
 *
 * Implementations MUST be stateless and deterministic for a given input
 * (idempotency: re-running on identical input must yield identical drafts).
 */
interface ChunkerInterface
{
    /**
     * Stable, lower-kebab-case identifier (e.g. 'markdown-section-aware', 'pdf-page-chunker').
     */
    public function name(): string;

    /**
     * Returns true if this chunker handles the given source-type token
     * (e.g. 'markdown', 'pdf', 'docx', 'text'). The source type is derived
     * from pipeline configuration during MIME/source normalisation
     * (the concrete config key is introduced in T1.4).
     *
     * Non-overlap requirement: no two registered chunkers may both return
     * true for the same source-type. Ambiguity is a configuration bug.
     */
    public function supports(string $sourceType): bool;

    /**
     * Split the converted document into ordered chunk drafts.
     *
     * @return list<ChunkDraft>
     */
    public function chunk(ConvertedDocument $doc): array;
}
