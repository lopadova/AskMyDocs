<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Flow\Definitions\PruneChatLogsFlow;
use App\Models\ChatLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;

/**
 * Scheduled command that rotates chat_logs older than CHAT_LOG_RETENTION_DAYS.
 *
 * v4.2/sub-PR 3d — refactored onto {@see PruneChatLogsFlow}. Iterates
 * DISTINCT tenant_ids that have at least one chat_log older than the
 * cutoff and dispatches ONE Flow execute call per tenant (single
 * tenant when --tenant=X is supplied).
 */
class PruneChatLogsCommand extends Command
{
    protected $signature = 'chat-log:prune
                            {--days= : Override CHAT_LOG_RETENTION_DAYS}
                            {--tenant= : Restrict to a single tenant_id (default: every tenant with eligible rows)}
                            {--dry-run : Count rows without deleting}';

    protected $description = 'Rotate chat_logs by deleting rows older than N days.';

    public function handle(TenantContext $context): int
    {
        $days = (int) ($this->option('days') ?? config('chat-log.retention_days', 90));

        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping rotation.');
            return self::SUCCESS;
        }

        $cutoff = CarbonImmutable::now()->subDays($days);
        $cutoffIso = $cutoff->toIso8601String();
        $dryRun = (bool) $this->option('dry-run');

        $tenantIds = $this->resolveTenantIds($cutoff);
        if ($tenantIds === []) {
            $this->info('No tenants have chat_logs older than the cutoff. Nothing to do.');
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
                    $this->error("[{$tenantId}] kb.prune-chat-logs [{$run->status}] at step [{$failedStep}].");
                    $exitCode = self::FAILURE;
                    continue;
                }

                $count = $this->extractCount($run, $dryRun);
                $totalDeleted += $count;
                $verb = $dryRun ? 'Would delete' : 'Deleted';
                // Plural form (`rows` not `row(s)`) preserves the v3 CLI
                // wording so existing operator scripts + test assertions
                // keep working unchanged across the v4.2 refactor.
                $this->info("[{$tenantId}] {$verb} {$count} chat_logs rows older than {$days} days (cutoff: {$cutoffIso}).");
            }
        } finally {
            $context->set($previousTenant);
        }

        $verb = $dryRun ? 'Total planned deletion' : 'Total deleted';
        $this->info("{$verb}: {$totalDeleted} row(s) across ".count($tenantIds).' tenant(s).');
        return $exitCode;
    }

    private function runFlow(string $tenantId, string $cutoffIso, bool $dryRun): FlowRun
    {
        $options = FlowExecutionOptions::make(
            correlationId: $tenantId,
            idempotencyKey: "prune-chat-logs:{$tenantId}:{$cutoffIso}",
        );
        $input = ['tenant_id' => $tenantId, 'cutoff_iso' => $cutoffIso];

        return $dryRun
            ? Flow::dryRun(PruneChatLogsFlow::NAME, $input, $options)
            : Flow::execute(PruneChatLogsFlow::NAME, $input, $options);
    }

    private function extractCount(FlowRun $run, bool $dryRun): int
    {
        if ($dryRun) {
            $countResult = $run->stepResults['count-stale-chat-logs'] ?? null;
            return $countResult instanceof \Padosoft\LaravelFlow\FlowStepResult
                ? (int) ($countResult->output['planned_count'] ?? 0)
                : 0;
        }
        $deleteResult = $run->stepResults['delete-stale-chat-logs'] ?? null;
        return $deleteResult instanceof \Padosoft\LaravelFlow\FlowStepResult
            ? (int) ($deleteResult->output['deleted_count'] ?? 0)
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
        return ChatLog::query()
            ->where('created_at', '<', $cutoff)
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
