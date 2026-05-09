<?php

declare(strict_types=1);

namespace App\Flow\Compensators;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeDocument;
use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Compensator for the `soft-delete` step of {@see \App\Flow\Definitions\DeleteDocumentFlow}.
 *
 * Triggered when the post-soft-delete step (`hard-delete-rows`) fails
 * (cascade FK violation, audit-write failure, etc.). Calls
 * `$document->restore()` IF the soft-delete step actually trashed the
 * row in this run — pre-existing trashed rows are preserved in their
 * original state so the operator's prior soft-delete intent survives.
 *
 * Per R4 + R14 — never silently swallow a restore failure. Letting the
 * exception propagate marks `flow_runs.compensation_status = failed`.
 *
 * Idempotent: a second invocation finds the row already restored and
 * returns cleanly.
 */
final class RestoreSoftDeletedCompensator implements FlowCompensator
{
    public function compensate(FlowContext $context, FlowStepResult $stepResult): void
    {
        StepTenantBinder::bindFromContext($context);

        $output = $stepResult->output;
        $documentId = (int) ($output['document_id'] ?? 0);
        $newlyTrashed = (bool) ($output['newly_trashed'] ?? false);

        if ($documentId <= 0) {
            return;
        }
        if (! $newlyTrashed) {
            // Don't restore a row that was already trashed BEFORE this run
            // touched it — preserve the operator's prior soft-delete intent.
            return;
        }

        $document = KnowledgeDocument::onlyTrashed()->find($documentId);
        if ($document === null) {
            // Already restored (idempotent) or hard-deleted by another path.
            return;
        }

        $document->restore();
    }
}
