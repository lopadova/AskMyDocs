<?php

declare(strict_types=1);

namespace App\Flow\Steps\Delete;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 3 of {@see \App\Flow\Definitions\DeleteDocumentFlow}.
 *
 * When `input['force'] === true`, calls
 * {@see DocumentDeleter::deleteRowsOnly()} to remove the document row +
 * chunks (FK cascade) + canonical graph nodes/edges + write the
 * deprecation audit row, but PRESERVES the file on disk so the next step
 * (`remove-file`) can handle deletion as a separate observable phase.
 *
 * When `force` is false, this step is a no-op (soft delete sequence ends
 * here).
 *
 * NOT REVERSIBLE: hard delete cannot be unwound. The previous
 * `soft-delete` step's compensator runs IF this step fails (e.g.
 * cascade FK violation), but once `forceDelete()` commits, no
 * compensator can undo it.
 *
 * Dry-run skipped — DB write is the only artefact.
 */
final class HardDeleteRowsStep implements FlowStepHandler
{
    public function __construct(
        private readonly DocumentDeleter $deleter,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $force = (bool) ($context->input['force'] ?? false);
        if (! $force) {
            return FlowStepResult::success(
                output: ['skipped' => true, 'reason' => 'soft_delete_only'],
                businessImpact: ['hard_deleted' => false],
            );
        }

        $loadOutput = $context->stepOutputs['load-document'] ?? null;
        if (! is_array($loadOutput) || ! ($loadOutput['found'] ?? false)) {
            return FlowStepResult::success(
                output: ['skipped' => true, 'reason' => 'document_not_found'],
                businessImpact: ['hard_deleted' => false],
            );
        }

        $documentId = (int) $loadOutput['document_id'];
        // R2 — withTrashed because soft-delete-step preceded us.
        $document = KnowledgeDocument::withTrashed()->find($documentId);
        if ($document === null) {
            throw new RuntimeException(
                "HardDeleteRowsStep: KnowledgeDocument [{$documentId}] vanished mid-flow."
            );
        }

        $result = $this->deleter->deleteRowsOnly($document);

        return FlowStepResult::success(
            output: [
                'document_id' => $result['document_id'],
                'project_key' => $result['project_key'],
                'source_path' => $result['source_path'],
                'disk' => $result['disk'],
                'full_path' => $result['full_path'],
                'canonical' => $result['canonical'],
                'hard_deleted' => true,
            ],
            businessImpact: [
                'hard_deleted' => true,
                'canonical_was' => $result['canonical'],
            ],
        );
    }
}
