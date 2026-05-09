<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Flow\Definitions\PruneEmbeddingCacheFlow;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;

/**
 * Scheduled command that evicts embedding_cache rows older than
 * KB_EMBEDDING_CACHE_RETENTION_DAYS.
 *
 * v4.2/sub-PR 3d — refactored onto {@see PruneEmbeddingCacheFlow}.
 * embedding_cache is intentionally cross-tenant by design, so this
 * command runs the Flow ONCE (no per-tenant fan-out). The --tenant
 * option is accepted for audit-trail consistency (the Flow run record
 * carries the supplied tenant_id) but does not change which rows are
 * evicted.
 *
 * The Flow contains a CONDITIONAL approval gate: when the projected
 * eviction count exceeds `kb.embedding_cache.approval_threshold` the
 * run pauses with status=paused and an approval token; the CLI emits
 * a notice with the token's resume URL and exits with FAILURE so the
 * scheduler retries on the next tick (and the operator has time to
 * approve via flow-admin / Flow::resume()).
 */
class PruneEmbeddingCacheCommand extends Command
{
    protected $signature = 'kb:prune-embedding-cache
                            {--days= : Override KB_EMBEDDING_CACHE_RETENTION_DAYS}
                            {--tenant= : tenant_id stamped onto the Flow run record (default: current tenant)}
                            {--dry-run : Count rows without evicting}';

    protected $description = 'Remove embedding_cache rows that have not been used in the last N days.';

    public function handle(TenantContext $context): int
    {
        $days = (int) ($this->option('days') ?? config('kb.embedding_cache.retention_days', 30));

        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping prune.');
            return self::SUCCESS;
        }

        $cutoff = CarbonImmutable::now()->subDays($days);
        $cutoffIso = $cutoff->toIso8601String();
        $dryRun = (bool) $this->option('dry-run');

        $tenantId = (string) ($this->option('tenant') ?? $context->current());
        $previousTenant = $context->current();

        try {
            $context->set($tenantId);

            $options = FlowExecutionOptions::make(
                correlationId: $tenantId,
                idempotencyKey: "prune-embedding-cache:{$tenantId}:{$cutoffIso}",
            );
            $input = ['tenant_id' => $tenantId, 'cutoff_iso' => $cutoffIso];

            $run = $dryRun
                ? Flow::dryRun(PruneEmbeddingCacheFlow::NAME, $input, $options)
                : Flow::execute(PruneEmbeddingCacheFlow::NAME, $input, $options);

            return $this->reportRun($run, $days, $cutoffIso, $dryRun);
        } finally {
            $context->set($previousTenant);
        }
    }

    private function reportRun(FlowRun $run, int $days, string $cutoffIso, bool $dryRun): int
    {
        if ($run->status === FlowRun::STATUS_PAUSED) {
            $assess = $run->stepResults[PruneEmbeddingCacheFlow::ASSESS_STEP] ?? null;
            $planned = $assess instanceof \Padosoft\LaravelFlow\FlowStepResult
                ? (int) ($assess->output['planned_count'] ?? 0)
                : 0;
            $threshold = $assess instanceof \Padosoft\LaravelFlow\FlowStepResult
                ? (int) ($assess->output['threshold'] ?? 0)
                : 0;
            $token = $run->approvalTokens[PruneEmbeddingCacheFlow::ASSESS_STEP] ?? null;
            $tokenLine = $token !== null
                ? "  Approval token: {$token->plainToken} (run id: {$run->id})"
                : "  Run id: {$run->id} (no approval token captured — check flow-admin)";

            $this->warn("kb.prune-embedding-cache PAUSED for approval — planned eviction {$planned} > threshold {$threshold}.");
            $this->line($tokenLine);
            $this->line('Resume via Flow::resume($token) or the flow-admin SPA. The scheduler will retry on the next tick.');
            return self::FAILURE;
        }

        if ($run->status !== FlowRun::STATUS_SUCCEEDED) {
            $failedStep = $run->failedStep ?? '(unknown)';
            $this->error("kb.prune-embedding-cache [{$run->status}] at step [{$failedStep}].");
            return self::FAILURE;
        }

        if ($dryRun) {
            $countResult = $run->stepResults['count-stale-embeddings'] ?? null;
            $count = $countResult instanceof \Padosoft\LaravelFlow\FlowStepResult
                ? (int) ($countResult->output['planned_count'] ?? 0)
                : 0;
            $this->info("Would evict {$count} embedding_cache row(s) older than {$days} days (cutoff: {$cutoffIso}).");
            return self::SUCCESS;
        }

        $deleteResult = $run->stepResults['evict-embedding-cache'] ?? null;
        $deleted = $deleteResult instanceof \Padosoft\LaravelFlow\FlowStepResult
            ? (int) ($deleteResult->output['deleted_count'] ?? 0)
            : 0;
        $this->info("Pruned {$deleted} embedding_cache rows older than {$days} days (cutoff: {$cutoffIso}).");
        return self::SUCCESS;
    }
}
