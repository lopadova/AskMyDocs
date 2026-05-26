<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Flow\Definitions\RebuildGraphFlow;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;

/**
 * Rebuild the canonical knowledge graph (kb_nodes + kb_edges) from scratch.
 *
 * Use cases:
 *   - schema evolved and existing graph rows carry stale structure;
 *   - a batch re-ingest populated frontmatter_json but the job queue was
 *     disabled / backed up;
 *   - as a nightly consistency sweep (scheduled at 03:40 daily).
 *
 * v4.2/sub-PR 3d — refactored onto {@see RebuildGraphFlow}. Iterates
 * DISTINCT tenant_ids that have at least one canonical doc and
 * dispatches ONE Flow execute call per tenant (single tenant when
 * --tenant=X is supplied).
 *
 * Idempotent and safe to run on-demand. The Flow's idempotencyKey is
 * salted with hrtime() so re-runs after a truncate ALWAYS re-execute
 * (the engine's per-(name, key) dedup would otherwise short-circuit
 * the second run and leave the graph empty).
 */
class KbRebuildGraphCommand extends Command
{
    protected $signature = 'kb:rebuild-graph
        {--project= : Limit to a single project_key (default: all projects)}
        {--tenant= : Restrict to a single tenant_id (default: every tenant with canonical docs)}
        {--no-truncate : Skip the initial delete of existing nodes/edges (additive rebuild)}
        {--sync : Run indexer jobs synchronously instead of dispatching to the queue}';

    protected $description = 'Rebuild canonical kb_nodes + kb_edges from existing canonical documents.';

    public function handle(TenantContext $context): int
    {
        $projectKey = (string) ($this->option('project') ?? '');
        $truncate = ! (bool) $this->option('no-truncate');
        $sync = (bool) $this->option('sync');

        $tenantIds = $this->resolveTenantIds($projectKey);
        if ($tenantIds === []) {
            $this->info($projectKey === ''
                ? 'No canonical documents found across any tenant. Nothing to do.'
                : "No canonical documents found for project '{$projectKey}' across any tenant. Nothing to do.");
            return self::SUCCESS;
        }

        $previousTenant = $context->current();
        $totalDispatched = 0;
        $exitCode = self::SUCCESS;

        try {
            foreach ($tenantIds as $tenantId) {
                $context->set($tenantId);
                $run = $this->runFlow($tenantId, $projectKey, $truncate, $sync);

                if ($run->status !== FlowRun::STATUS_SUCCEEDED) {
                    $failedStep = $run->failedStep ?? '(unknown)';
                    $this->error("[{$tenantId}] kb.rebuild-graph [{$run->status}] at step [{$failedStep}].");
                    $exitCode = self::FAILURE;
                    continue;
                }

                $count = $this->extractDispatched($run);
                $totalDispatched += $count;
                $verb = $sync ? 'Rebuilt' : 'Dispatched';
                $scope = $projectKey === '' ? 'all projects' : "project '{$projectKey}'";
                $this->info("[{$tenantId}] {$verb} {$count} indexer job(s) for {$scope}.");
            }
        } finally {
            $context->set($previousTenant);
        }

        $verb = $sync ? 'Total rebuilt' : 'Total dispatched';
        $this->info("{$verb}: {$totalDispatched} indexer job(s) across ".count($tenantIds).' tenant(s).');
        return $exitCode;
    }

    private function runFlow(string $tenantId, string $projectKey, bool $truncate, bool $sync): FlowRun
    {
        // hrtime nonce so re-runs after a truncate ALWAYS re-execute. Without
        // the nonce the engine's per-(name, key) dedup would short-circuit
        // the second run and leave kb_nodes/kb_edges empty.
        $nonce = (string) hrtime(true);
        $projectScope = $projectKey === '' ? 'all' : $projectKey;

        $options = FlowExecutionOptions::make(
            correlationId: $tenantId,
            idempotencyKey: "rebuild-graph:{$tenantId}:{$projectScope}:{$nonce}",
        );
        $input = [
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'truncate' => $truncate,
            'sync' => $sync,
        ];

        return Flow::execute(RebuildGraphFlow::NAME, $input, $options);
    }

    private function extractDispatched(FlowRun $run): int
    {
        $result = $run->stepResults['dispatch-canonical-indexer'] ?? null;
        return $result instanceof \Padosoft\LaravelFlow\FlowStepResult
            ? (int) ($result->output['dispatched_count'] ?? 0)
            : 0;
    }

    /**
     * @return list<string>
     */
    private function resolveTenantIds(string $projectKey): array
    {
        $explicit = (string) ($this->option('tenant') ?? '');
        if ($explicit !== '') {
            return [$explicit];
        }
        $query = KnowledgeDocument::query()->canonical();
        if ($projectKey !== '') {
            $query->where('project_key', $projectKey);
        }
        return $query
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
