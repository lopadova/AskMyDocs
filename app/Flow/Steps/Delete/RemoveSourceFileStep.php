<?php

declare(strict_types=1);

namespace App\Flow\Steps\Delete;

use App\Flow\Steps\StepTenantBinder;
use App\Services\Kb\DocumentDeleter;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 4 of {@see \App\Flow\Definitions\DeleteDocumentFlow}.
 *
 * Removes the physical source file from the KB disk via
 * {@see DocumentDeleter::removeFileFor()}. Runs only when:
 *   - input['force'] === true (hard delete requested)
 *   - input['keep_file'] !== true (operator did NOT explicitly opt out)
 *   - the prior `hard-delete-rows` step actually executed
 *
 * No compensator: file deletion is the LAST step + irreversible.
 * If this step fails the operator sees the failure but the DB rows are
 * already gone (the prior soft-delete compensator only restores the
 * deleted_at; it cannot recreate the row + chunks + graph).
 *
 * Dry-run skipped — disk write is the only artefact.
 */
final class RemoveSourceFileStep implements FlowStepHandler
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
        $keepFile = (bool) ($context->input['keep_file'] ?? false);
        if (! $force || $keepFile) {
            return FlowStepResult::success(
                output: ['skipped' => true, 'reason' => $force ? 'keep_file_requested' : 'soft_delete_only'],
                businessImpact: ['file_deleted' => false],
            );
        }

        $hardDeleteOutput = $context->stepOutputs['hard-delete-rows'] ?? null;
        if (! is_array($hardDeleteOutput) || ! ($hardDeleteOutput['hard_deleted'] ?? false)) {
            return FlowStepResult::success(
                output: ['skipped' => true, 'reason' => 'rows_not_deleted'],
                businessImpact: ['file_deleted' => false],
            );
        }

        $disk = (string) ($hardDeleteOutput['disk'] ?? '');
        $fullPath = (string) ($hardDeleteOutput['full_path'] ?? '');
        $documentId = (int) ($hardDeleteOutput['document_id'] ?? 0);
        $sourcePath = (string) ($hardDeleteOutput['source_path'] ?? '');

        if ($disk === '' || $fullPath === '') {
            throw new RuntimeException(
                'RemoveSourceFileStep: hard-delete-rows step did not provide disk + full_path.'
            );
        }

        $deleted = $this->deleter->removeFileFor($disk, $fullPath, $documentId, $sourcePath);

        return FlowStepResult::success(
            output: [
                'file_deleted' => $deleted,
                'disk' => $disk,
                'full_path' => $fullPath,
            ],
            businessImpact: ['file_deleted' => $deleted],
        );
    }
}
