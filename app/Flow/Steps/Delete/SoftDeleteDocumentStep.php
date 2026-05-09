<?php

declare(strict_types=1);

namespace App\Flow\Steps\Delete;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeDocument;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 2 of {@see \App\Flow\Definitions\DeleteDocumentFlow}.
 *
 * Sets `deleted_at` via Eloquent's SoftDeletes trait. Idempotent: an
 * already-trashed row returns success without re-triggering the global
 * `deleting` event chain.
 *
 * Compensator: {@see \App\Flow\Compensators\RestoreSoftDeletedCompensator}
 * calls `$document->restore()` if a downstream step (hard-delete-rows or
 * remove-file) fails — restoring the soft-deleted state lets the operator
 * retry without losing the row.
 *
 * Dry-run skipped: this step's only artefact is the DB write.
 */
final class SoftDeleteDocumentStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $loadOutput = $context->stepOutputs['load-document'] ?? null;
        if (! is_array($loadOutput)) {
            throw new RuntimeException(
                'SoftDeleteDocumentStep: missing prior step output [load-document].'
            );
        }
        if (! ($loadOutput['found'] ?? false)) {
            // Upstream short-circuit (document not found). Propagate.
            return FlowStepResult::success(
                output: ['skipped' => true, 'reason' => 'document_not_found'],
                businessImpact: ['soft_deleted' => false],
            );
        }

        $documentId = (int) $loadOutput['document_id'];
        // R30 — explicit tenant scope on the read; trait only auto-fills
        // tenant_id on CREATE.
        $tenantId = (string) $context->input['tenant_id'];
        $document = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->withTrashed()
            ->find($documentId);
        if ($document === null) {
            throw new RuntimeException(
                "SoftDeleteDocumentStep: KnowledgeDocument [{$documentId}] vanished mid-flow."
            );
        }

        $alreadyTrashed = (bool) $document->trashed();
        if (! $alreadyTrashed) {
            $document->delete();
        }

        return FlowStepResult::success(
            output: [
                'document_id' => $documentId,
                'project_key' => (string) $document->project_key,
                'source_path' => (string) $document->source_path,
                // Compensator uses this to decide whether to restore: only
                // restore rows THIS run trashed (preserve the prior trashed
                // state on rollback).
                'newly_trashed' => ! $alreadyTrashed,
                'already_trashed' => $alreadyTrashed,
            ],
            businessImpact: [
                'soft_deleted' => true,
                'newly_trashed' => ! $alreadyTrashed,
            ],
        );
    }
}
