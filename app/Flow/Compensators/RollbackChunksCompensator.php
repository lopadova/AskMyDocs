<?php

declare(strict_types=1);

namespace App\Flow\Compensators;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Compensator for the `persist-chunks` step of the {@see \App\Flow\Definitions\IngestDocumentFlow}.
 *
 * Triggered when any step AFTER `persist-chunks` fails (currently only
 * `maybe-dispatch-canonical-indexer`). Force-deletes the
 * KnowledgeDocument that was just inserted so the saga unwinds cleanly:
 *
 *   - `knowledge_chunks` rows cascade via FK ON DELETE CASCADE.
 *   - `kb_nodes` / `kb_edges` cascade via {@see DocumentDeleter::cascadeGraphFor()}.
 *   - The physical KB file is preserved (the original file on disk was
 *     not written by this saga — IngestDocumentJob assumes the bytes
 *     already exist on the configured disk; deleting them would damage
 *     external state). DocumentDeleter::removeFile() is gated on the
 *     metadata stored on the doc, and our forceDelete() invocation goes
 *     through DocumentDeleter::delete($doc, force: true) which DOES
 *     remove the file. We accept that — a failed ingest's partial
 *     rollback semantics include reverting the file so the next ingest
 *     attempt re-uploads it cleanly.
 *
 * Idempotent: if the document was already deleted (race with another
 * compensator invocation, or already cleaned up by a sibling rollback),
 * the second pass is a no-op.
 */
final class RollbackChunksCompensator implements FlowCompensator
{
    public function __construct(
        private readonly DocumentDeleter $deleter,
    ) {}

    public function compensate(FlowContext $context, FlowStepResult $stepResult): void
    {
        StepTenantBinder::bindFromContext($context);

        $output = $stepResult->output;
        $documentId = (int) ($output['knowledge_document_id'] ?? 0);
        if ($documentId <= 0) {
            return;
        }

        // R2 — soft-deleted rows must remain reachable here so the
        // compensator can promote a soft delete to a hard delete on the
        // (rare) re-entry path.
        $document = KnowledgeDocument::withTrashed()->find($documentId);
        if ($document === null) {
            // Already gone — saga rollback is idempotent by contract.
            return;
        }

        try {
            $this->deleter->delete($document, force: true);
        } catch (\Throwable $e) {
            // Log but do not re-throw — the engine has already marked the
            // run as failed; throwing here would mask the root cause.
            Log::warning('RollbackChunksCompensator: force-delete failed', [
                'flow_run_id' => $context->flowRunId,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
