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
 * forceDelete inside chunkById(100). Two tenants may legitimately share
 * the same `project_key`, so we MUST pass the caller's tenant_id
 * through to the deleter so its query is scoped via `->forTenant($id)`
 * and never cascades into another tenant's rows. StepTenantBinder
 * binds the TenantContext singleton, but the deleter's query string
 * does NOT auto-filter on tenant_id — `BelongsToTenant` only
 * auto-fills on CREATE — so passing tenant_id explicitly is the
 * load-bearing isolation guarantee. Copilot iter 1 finding (PR #117).
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
        $tenantId = (string) ($context->input['tenant_id'] ?? '');

        if ($projectKey === '') {
            throw new RuntimeException(
                'PruneOrphansStep: input["project_key"] must be a non-empty string.'
            );
        }
        if ($tenantId === '') {
            // R30 — the deleter falls back to an unscoped sweep when
            // tenant_id is missing; reject the run rather than risk
            // cross-tenant deletes from a Flow that exists exactly to
            // run per-tenant.
            throw new RuntimeException(
                'PruneOrphansStep: input["tenant_id"] must be a non-empty string for R30 isolation.'
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
            tenantId: $tenantId,
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
