<?php

declare(strict_types=1);

namespace App\Flow\Definitions;

use App\Flow\Compensators\RollbackChunksCompensator;
use App\Flow\Steps\ChunkDocumentStep;
use App\Flow\Steps\EmbedChunksStep;
use App\Flow\Steps\MaybeDispatchCanonicalIndexerStep;
use App\Flow\Steps\ParseMarkdownStep;
use App\Flow\Steps\PersistChunksStep;
use Padosoft\LaravelFlow\FlowEngine;

/**
 * `kb.ingest` — 5-step saga mirroring the {@see \App\Services\Kb\DocumentIngestor::ingest()}
 * pipeline. Decomposed onto laravel-flow v1.0 so each phase becomes
 * independently observable (flow_steps + flow_audit rows) and the persist
 * step gets a compensator that rolls back the document + chunks if any
 * downstream step fails.
 *
 * Steps:
 *   1. parse-markdown                  (dry-run-safe)
 *      Reads bytes from the configured disk, runs the registered Converter
 *      for the MIME type, parses canonical frontmatter (graceful degrade).
 *   2. chunk-document                  (dry-run-safe)
 *      Routes the converted markdown through the Chunker registered for
 *      the source-type token.
 *   3. embed-chunks                    (NOT dry-run; embeddings cost money)
 *      Calls EmbeddingCacheService — cache hits are free, misses go to the
 *      provider. Returns embeddings in input-text order.
 *   4. persist-chunks                  (mutates DB)
 *      Single transaction: archive prior versions, vacate canonical
 *      identifiers, insert KnowledgeDocument + KnowledgeChunk rows.
 *      ▶ Compensator: RollbackChunksCompensator force-deletes the doc.
 *   5. maybe-dispatch-canonical-indexer
 *      If is_canonical=true, dispatches CanonicalIndexerJob. Idempotent;
 *      always succeeds (the queued job has its own retry).
 *
 * Idempotency:
 *   IngestDocumentJob passes `idempotencyKey="{tenant}:{project}:{path}"`.
 *   On re-execution with the same key the engine returns the existing
 *   FlowRun. Content-level dedup still happens INSIDE the persist step
 *   via DocumentIngestor::findExistingVersion() (SHA-256 version_hash).
 */
final class IngestDocumentFlow
{
    public const NAME = 'kb.ingest';

    public static function register(FlowEngine $engine): void
    {
        $engine->define(self::NAME)
            ->withInput([
                // R30/R31 — tenant_id travels through the input bag because
                // FlowContext does not expose FlowExecutionOptions to handlers.
                // Steps re-bind it on TenantContext via StepTenantBinder.
                'tenant_id',
                'project_key',
                'source_path',
                'disk',
                'title',
                'metadata',
                'mime_type',
            ])
            ->step('parse-markdown', ParseMarkdownStep::class)
                ->withDryRun(true)
            ->step('chunk-document', ChunkDocumentStep::class)
                ->withDryRun(true)
            ->step('embed-chunks', EmbedChunksStep::class)
            ->step('persist-chunks', PersistChunksStep::class)
                ->compensateWith(RollbackChunksCompensator::class)
            ->step('maybe-dispatch-canonical-indexer', MaybeDispatchCanonicalIndexerStep::class)
            ->register();
    }
}
