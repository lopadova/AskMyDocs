<?php

declare(strict_types=1);

namespace App\Flow\Steps\Graph;

use App\Flow\Steps\StepTenantBinder;
use App\Jobs\CanonicalIndexerJob;
use App\Models\KnowledgeDocument;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Step 3 of {@see \App\Flow\Definitions\RebuildGraphFlow}.
 *
 * Walks the bound tenant's canonical documents (optionally scoped to a
 * single project_key) with chunkById(100) and dispatches one
 * {@see CanonicalIndexerJob} per row. Each indexer job is a
 * `kb.canonical-index` Flow run that handles its own tenant binding +
 * compensation; this step only orchestrates the fan-out.
 *
 * `input['sync']` controls dispatch mode:
 *   - true  → run the indexer inline (dispatchSync) for small batches
 *             where operators want immediate feedback;
 *   - false → push to the queue (dispatchRebuild — sets forceReindex
 *             flag so the engine-level idempotency cache is bypassed
 *             and the indexer re-runs even when (tenant, doc, version)
 *             is unchanged, which is required after a graph truncate).
 *
 * R3 (memory-safe bulk ops) — chunkById(100).
 * R30 — explicit `forTenant()` on the read.
 *
 * Dry-run skipped — dispatch IS the side effect.
 */
final class DispatchCanonicalIndexerFanOutStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $tenantId = (string) $context->input['tenant_id'];
        $projectKey = (string) ($context->input['project_key'] ?? '');
        $sync = (bool) ($context->input['sync'] ?? false);

        $query = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('is_canonical', true);
        if ($projectKey !== '') {
            $query->where('project_key', $projectKey);
        }

        $dispatched = 0;
        $query->orderBy('id')->chunkById(100, function ($docs) use (&$dispatched, $tenantId, $sync): void {
            foreach ($docs as $doc) {
                if ($sync) {
                    // Inline execution; the indexer Job's handle() already
                    // captures + restores TenantContext (PR #115 hardening).
                    (new CanonicalIndexerJob((int) $doc->id, $tenantId, true))->handle();
                } else {
                    // Queue dispatch. dispatchRebuild() sets forceReindex=true
                    // so the engine-level idempotency cache is bypassed (a
                    // truncate followed by a same-version re-dispatch must
                    // re-execute, otherwise kb_nodes/kb_edges stay empty).
                    CanonicalIndexerJob::dispatchRebuild((int) $doc->id, $tenantId);
                }
                $dispatched++;
            }
        });

        return FlowStepResult::success(
            output: [
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'sync' => $sync,
                'dispatched_count' => $dispatched,
            ],
            businessImpact: [
                'dispatched_count' => $dispatched,
                'sync' => $sync,
            ],
        );
    }
}
