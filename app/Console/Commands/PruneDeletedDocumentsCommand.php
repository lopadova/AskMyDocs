<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Flow\Definitions\PruneDeletedFlow;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;

/**
 * Scheduled command that hard-deletes soft-deleted documents (and their
 * original files on the KB disk) when their deleted_at is older than
 * KB_SOFT_DELETE_RETENTION_DAYS. Skipped when retention is 0 or negative.
 *
 * v4.2/sub-PR 3d — refactored onto {@see PruneDeletedFlow}. The CLI
 * iterates DISTINCT tenant_ids that have at least one soft-deleted row
 * older than the cutoff and dispatches ONE Flow execute call per tenant
 * (single tenant when --tenant=X is supplied). Each Flow run is scoped
 * to its tenant via StepTenantBinder so cross-tenant rows are
 * physically unreachable from inside the run.
 */
class PruneDeletedDocumentsCommand extends Command
{
    protected $signature = 'kb:prune-deleted
                            {--days= : Override KB_SOFT_DELETE_RETENTION_DAYS}
                            {--tenant= : Restrict to a single tenant_id (default: every tenant with eligible rows)}
                            {--dry-run : Count rows without deleting}';

    protected $description = 'Hard-delete soft-deleted knowledge documents (and their original files) older than N days.';

    public function handle(TenantContext $context): int
    {
        $days = (int) ($this->option('days') ?? config('kb.deletion.retention_days', 30));

        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping prune.');
            return self::SUCCESS;
        }

        $cutoff = CarbonImmutable::now()->subDays($days);
        $cutoffIso = $cutoff->toIso8601String();
        $dryRun = (bool) $this->option('dry-run');

        $tenantIds = $this->resolveTenantIds($cutoff);
        if ($tenantIds === []) {
            $this->info('No tenants have soft-deleted documents older than the cutoff. Nothing to do.');
            return self::SUCCESS;
        }

        $previousTenant = $context->current();
        $totalDeleted = 0;
        $exitCode = self::SUCCESS;

        try {
            foreach ($tenantIds as $tenantId) {
                $context->set($tenantId);
                $run = $this->runFlow($tenantId, $cutoffIso, $dryRun);

                if ($run->status !== FlowRun::STATUS_SUCCEEDED) {
                    $failedStep = $run->failedStep ?? '(unknown)';
                    $this->error("[{$tenantId}] kb.prune-deleted [{$run->status}] at step [{$failedStep}].");
                    $exitCode = self::FAILURE;
                    continue;
                }

                $count = $this->extractCount($run, $dryRun);
                $totalDeleted += $count;
                $verb = $dryRun ? 'Would prune' : 'Pruned';
                $this->info("[{$tenantId}] {$verb} {$count} soft-deleted document(s) older than {$days} days (cutoff: {$cutoffIso}).");
                // v8.36 / ADR 0030 §3 — additive (R27): rows whose source file
                // was NOT removed (still referenced by another version, or the
                // storage key was held / unlockable / lapsed). Printed only
                // when non-zero so a clean run stays quiet, and never an
                // error: the rows are gone and the orphan sweep takes the
                // bytes once nothing references them.
                $kept = $this->extractFilesKept($run, $dryRun);
                if ($kept > 0) {
                    $this->info("[{$tenantId}] files_kept={$kept} (source not removed; kb:prune-orphan-files takes them once unreferenced).");
                }
            }
        } finally {
            $context->set($previousTenant);
        }

        $verb = $dryRun ? 'Total planned eviction' : 'Total pruned';
        $this->info("{$verb}: {$totalDeleted} document(s) across ".count($tenantIds).' tenant(s).');
        return $exitCode;
    }

    private function runFlow(string $tenantId, string $cutoffIso, bool $dryRun): FlowRun
    {
        $options = FlowExecutionOptions::make(
            correlationId: $tenantId,
            idempotencyKey: "prune-deleted:{$tenantId}:{$cutoffIso}",
        );
        $input = ['tenant_id' => $tenantId, 'cutoff_iso' => $cutoffIso];

        return $dryRun
            ? Flow::dryRun(PruneDeletedFlow::NAME, $input, $options)
            : Flow::execute(PruneDeletedFlow::NAME, $input, $options);
    }

    private function extractCount(FlowRun $run, bool $dryRun): int
    {
        if ($dryRun) {
            $countResult = $run->stepResults['count-soft-deleted'] ?? null;
            return $countResult instanceof \Padosoft\LaravelFlow\FlowStepResult
                ? (int) ($countResult->output['planned_count'] ?? 0)
                : 0;
        }
        $deleteResult = $run->stepResults['hard-delete-soft-deleted'] ?? null;
        return $deleteResult instanceof \Padosoft\LaravelFlow\FlowStepResult
            ? (int) ($deleteResult->output['deleted_count'] ?? 0)
            : 0;
    }

    /** Rows whose source file survived the hard delete (0 on a dry run: nothing was removed). */
    private function extractFilesKept(FlowRun $run, bool $dryRun): int
    {
        if ($dryRun) {
            return 0;
        }
        $deleteResult = $run->stepResults['hard-delete-soft-deleted'] ?? null;

        return $deleteResult instanceof \Padosoft\LaravelFlow\FlowStepResult
            ? (int) ($deleteResult->output['files_kept'] ?? 0)
            : 0;
    }

    /**
     * @return list<string>
     */
    private function resolveTenantIds(CarbonImmutable $cutoff): array
    {
        $explicit = (string) ($this->option('tenant') ?? '');
        if ($explicit !== '') {
            return [$explicit];
        }

        // R30 — DISTINCT tenant_ids with eligible rows. Iterate ONLY the
        // tenants that have something to prune so we don't open empty
        // Flow runs for every tenant in the system.
        return KnowledgeDocument::query()
            ->onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
