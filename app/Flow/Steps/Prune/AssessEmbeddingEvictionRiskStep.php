<?php

declare(strict_types=1);

namespace App\Flow\Steps\Prune;

use App\Flow\Steps\StepTenantBinder;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Step 2 of {@see \App\Flow\Definitions\PruneEmbeddingCacheFlow} —
 * conditional approval gate.
 *
 * Reads the planned eviction count from step 1's output and compares it
 * against `config('kb.embedding_cache.approval_threshold')`. Behaviour:
 *
 *   - planned ≤ threshold → returns `success()` with `approval_required=false`.
 *     The downstream eviction step runs immediately.
 *   - planned > threshold → returns `paused()` with `approval_required=true`.
 *     The engine issues an approval token and the run sits at status=paused
 *     until an operator calls Flow::resume($token). After resume the
 *     downstream eviction step runs.
 *
 * This is the "conditional gate" pattern: instead of the built-in
 * approvalGate() (which always pauses) we own the pause decision so the
 * common low-risk case (small daily evictions) flows through without
 * operator intervention. Only large evictions surface for review — this
 * matches the operational rhythm of nightly cron jobs while preserving
 * a circuit-breaker on accidental mass eviction (e.g. retention_days
 * misconfiguration).
 *
 * Read-only — no mutation in either branch. Dry-run is treated like a
 * normal evaluation; the engine still records the would-be pause but
 * does not actually issue an approval token in dry-run mode.
 */
final class AssessEmbeddingEvictionRiskStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        $countOutput = $context->stepOutputs['count-stale-embeddings'] ?? [];
        $planned = (int) ($countOutput['planned_count'] ?? 0);
        $threshold = (int) config('kb.embedding_cache.approval_threshold', 5000);

        $approvalRequired = $planned > $threshold;

        if (! $approvalRequired || $context->dryRun) {
            // Auto-resolve. Dry-run is treated as auto-resolve so operators
            // can rehearse the saga without sitting on a pending approval.
            return FlowStepResult::success(
                output: [
                    'approval_required' => false,
                    'planned_count' => $planned,
                    'threshold' => $threshold,
                ],
                businessImpact: [
                    'approval_required' => false,
                    'planned_count' => $planned,
                ],
            );
        }

        // Over threshold — pause for operator approval. The engine will
        // issue an approval token and persist the pause; Flow::resume()
        // re-enters the saga at the next step.
        return FlowStepResult::paused(
            output: [
                'approval_required' => true,
                'planned_count' => $planned,
                'threshold' => $threshold,
            ],
            businessImpact: [
                'approval_required' => true,
                'planned_count' => $planned,
                'threshold' => $threshold,
            ],
        );
    }
}
