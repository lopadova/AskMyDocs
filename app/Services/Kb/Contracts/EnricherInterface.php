<?php

declare(strict_types=1);

namespace App\Services\Kb\Contracts;

use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\EnrichmentLevel;

/**
 * Contract for v3 ingestion-pipeline enrichers.
 *
 * Enrichers run AFTER chunking and BEFORE embedding. They mutate ChunkDraft
 * metadata (auto-tags, language, summary, entities, ...) based on the
 * project's configured EnrichmentLevel.
 *
 * v3.0 ships the interface stub only; concrete enrichers (LanguageDetector,
 * AutoTagger, SummaryGenerator, EntityExtractor, TopicClassifier) land in v3.1.
 */
interface EnricherInterface
{
    /**
     * Stable, lower-kebab-case identifier (e.g. 'language-detector', 'auto-tagger').
     */
    public function name(): string;

    /**
     * Returns true if this enricher should run at the given level.
     *
     * Convention:
     *   - LanguageDetector applies at NONE/BASIC/FULL
     *   - AutoTagger applies at BASIC/FULL
     *   - SummaryGenerator/EntityExtractor/TopicClassifier apply at FULL only
     */
    public function appliesAt(EnrichmentLevel $level): bool;

    /**
     * Enrich a chunk draft, returning a NEW ChunkDraft (DTO is immutable).
     *
     * @param  array<string, mixed>  $context  Per-document context (project_key, document_id, sibling chunks, ...).
     */
    public function enrich(ChunkDraft $chunk, array $context): ChunkDraft;
}
