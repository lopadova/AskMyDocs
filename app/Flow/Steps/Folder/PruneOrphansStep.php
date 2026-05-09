<?php

declare(strict_types=1);

namespace App\Flow\Steps\Folder;

use App\Flow\Steps\StepTenantBinder;
use App\Services\Kb\DocumentDeleter;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 3 of {@see \App\Flow\Definitions\IngestFolderFlow}.
 *
 * Optional. Skipped when `input['prune_orphans'] !== true`.
 *
 * Calls {@see DocumentDeleter::deleteOrphans()} to remove documents
 * under the base path whose source file no longer exists on disk.
 * The "existing" set is derived from the file list produced by
 * {@see ListFolderFilesStep} (relative-path-stripped) so the orphan
 * detection lines up exactly with what was just dispatched for ingest.
 *
 * R30 — DocumentDeleter::deleteOrphans() runs SQL-side filtering AND
 * forceDelete inside chunkById(100). The query uses `where('project_key',
 * $projectKey)` and `where('source_path', 'like', $base.'/%')` — the
 * implicit Eloquent global tenant scope is missing on KnowledgeDocument
 * (BelongsToTenant trait only auto-fills on CREATE), so the inner
 * deleter MUST be called only after StepTenantBinder has bound the
 * caller's tenant on TenantContext. The deleter's helpers
 * (cascadeGraphFor, writeDeprecationAudit) read the document's own
 * tenant_id, so this is safe — but we still rely on the caller to
 * have funneled the invocation per-tenant.
 *
 * Dry-run skipped — DB+disk mutation is the only artefact.
 */
final class PruneOrphansStep implements FlowStepHandler
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

        $shouldPrune = (bool) ($context->input['prune_orphans'] ?? false);
        if (! $shouldPrune) {
            return FlowStepResult::success(
                output: ['skipped' => true, 'reason' => 'prune_orphans_disabled'],
                businessImpact: ['orphans_deleted' => 0],
            );
        }

        $listOutput = $context->stepOutputs['list-files'] ?? null;
        if (! is_array($listOutput)) {
            throw new RuntimeException(
                'PruneOrphansStep: missing prior step output [list-files].'
            );
        }

        $files = $listOutput['matched_files'] ?? [];
        if (! is_array($files)) {
            $files = [];
        }

        $projectKey = (string) ($context->input['project_key'] ?? '');
        $basePath = (string) ($context->input['relative_base_path'] ?? '');
        $prefix = (string) ($context->input['prefix'] ?? '');
        $force = $context->input['force_delete'] ?? null;
        if ($force !== null) {
            $force = (bool) $force;
        }

        if ($projectKey === '') {
            throw new RuntimeException(
                'PruneOrphansStep: input["project_key"] must be a non-empty string.'
            );
        }

        $existing = array_values(array_map(
            fn (string $full): string => $this->stripPrefix($full, $prefix),
            array_filter($files, static fn ($f): bool => is_string($f)),
        ));

        $removed = $this->deleter->deleteOrphans(
            projectKey: $projectKey,
            basePath: trim($basePath, '/'),
            existingRelativePaths: $existing,
            force: $force,
        );

        return FlowStepResult::success(
            output: [
                'project_key' => $projectKey,
                'base_path' => $basePath,
                'force' => $force,
                'orphans_deleted_count' => count($removed),
                'orphans_deleted' => $removed,
            ],
            businessImpact: [
                'orphans_deleted_count' => count($removed),
            ],
        );
    }

    private function stripPrefix(string $path, string $prefix): string
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return ltrim($path, '/');
        }
        $path = ltrim($path, '/');
        if (str_starts_with($path, $prefix.'/')) {
            return substr($path, strlen($prefix) + 1);
        }
        return $path;
    }
}
