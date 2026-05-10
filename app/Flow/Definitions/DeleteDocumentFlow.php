<?php

declare(strict_types=1);

namespace App\Flow\Definitions;

use App\Flow\Compensators\RestoreSoftDeletedCompensator;
use App\Flow\Steps\Delete\HardDeleteRowsStep;
use App\Flow\Steps\Delete\LoadDocumentForDeleteStep;
use App\Flow\Steps\Delete\RemoveSourceFileStep;
use App\Flow\Steps\Delete\SoftDeleteDocumentStep;
use Padosoft\LaravelFlow\FlowEngine;

/**
 * `kb.delete` — 4-step refactor of {@see \App\Services\Kb\DocumentDeleter::delete()}
 * for async-delete semantics.
 *
 * Steps:
 *   1. load-document            (dry-run-safe, withTrashed)
 *      Resolves the row by document_id OR (project_key, source_path).
 *      Emits `found=false` when nothing matches; downstream steps honour it.
 *   2. soft-delete              (mutates DB)
 *      Sets deleted_at via SoftDeletes. Tracks newly-trashed-by-this-run
 *      so the compensator only restores rows IT trashed.
 *      ▶ Compensator: RestoreSoftDeletedCompensator restores newly-trashed.
 *   3. hard-delete-rows         (mutates DB; only when input.force=true)
 *      Calls DocumentDeleter::deleteRowsOnly() — chunks cascade + graph
 *      cascade + deprecation audit. Skips file removal so step 4 owns it.
 *      No compensator: hard delete is irreversible — soft-delete's
 *      compensator runs IF this step fails (cascade FK violation etc.),
 *      restoring the row to its pre-flow state.
 *   4. remove-file              (mutates disk; only when force=true AND
 *                               keep_file=false AND step 3 actually ran)
 *      Calls DocumentDeleter::removeFileFor(). No compensator: file
 *      deletion is the LAST step + irreversible.
 *
 * Backward compatibility: callers (KbDeleteCommand, KbDeleteController)
 * map their existing `?bool $force` argument to `input['force']`. When
 * force is null/false the saga ends after step 2 — same UX as the legacy
 * `softDelete()` path. When force is true the saga runs all 4 steps and
 * matches the legacy `forceDelete()` path's net effect.
 *
 * Tenant context: every step starts with StepTenantBinder which fails
 * loud on missing/empty `tenant_id`. Callers MUST set the active tenant
 * on TenantContext before dispatch (and pass `tenant_id` in input).
 */
final class DeleteDocumentFlow
{
    public const NAME = 'kb.delete';

    public static function register(FlowEngine $engine): void
    {
        $engine->define(self::NAME)
            ->withInput([
                // R30/R31 — tenant_id rides the input bag.
                'tenant_id',
                // Optional: caller sets either document_id (>0) OR
                //   (project_key, source_path). Validation lives in the
                //   load-document step so invalid combinations fail loud.
                //   - document_id: int
                //   - project_key: string
                //   - source_path: string
                //   - force:       bool (default false → soft delete only)
                //   - keep_file:   bool (default false → file removed when force=true)
            ])
            ->step('load-document', LoadDocumentForDeleteStep::class)
                ->withDryRun(true)
            ->step('soft-delete', SoftDeleteDocumentStep::class)
                ->compensateWith(RestoreSoftDeletedCompensator::class)
            ->step('hard-delete-rows', HardDeleteRowsStep::class)
            ->step('remove-file', RemoveSourceFileStep::class)
            ->register();
    }
}
