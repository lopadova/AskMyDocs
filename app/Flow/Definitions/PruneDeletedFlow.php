<?php

declare(strict_types=1);

namespace App\Flow\Definitions;

use App\Flow\Steps\Prune\CountSoftDeletedDocumentsStep;
use App\Flow\Steps\Prune\HardDeleteSoftDeletedStep;
use Padosoft\LaravelFlow\FlowEngine;

/**
 * `kb.prune-deleted` — 2-step refactor of {@see \App\Console\Commands\PruneDeletedDocumentsCommand}.
 *
 * Steps:
 *   1. count-soft-deleted        (dry-run-safe)
 *      Counts soft-deleted KnowledgeDocument rows older than the cutoff
 *      so operators see the planned blast radius before any hard delete
 *      fires. Tenant-scoped via forTenant().
 *   2. hard-delete-soft-deleted  (mutates DB + disk)
 *      Walks the same set with chunkById(100) and routes each row through
 *      DocumentDeleter::delete($row, force=true). No dedicated compensator:
 *      hard delete is irreversible by design (the file is the source-of-
 *      truth and a soft-deleted row's file may already be removed). The
 *      operator's safety net is the cron schedule — a wrong cutoff
 *      manifests as zero-rows-deleted, not a destroyed corpus.
 *
 * Tenant fan-out: the CLI command (and any other caller) iterates
 * DISTINCT tenant_ids that have soft-deleted rows older than the cutoff
 * and dispatches ONE Flow execute call per tenant. Each run is scoped
 * to its own tenant via StepTenantBinder.
 */
final class PruneDeletedFlow
{
    public const NAME = 'kb.prune-deleted';

    public static function register(FlowEngine $engine): void
    {
        $engine->define(self::NAME)
            ->withInput([
                // R30/R31 — tenant_id rides the input bag.
                'tenant_id',
                'cutoff_iso',
            ])
            ->step('count-soft-deleted', CountSoftDeletedDocumentsStep::class)
                ->withDryRun(true)
            ->step('hard-delete-soft-deleted', HardDeleteSoftDeletedStep::class)
            ->register();
    }
}
