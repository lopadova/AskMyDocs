<?php

declare(strict_types=1);

namespace App\Flow\Definitions;

use App\Flow\Steps\Prune\AssessEmbeddingEvictionRiskStep;
use App\Flow\Steps\Prune\CountStaleEmbeddingsStep;
use App\Flow\Steps\Prune\EvictEmbeddingCacheStep;
use Padosoft\LaravelFlow\FlowEngine;

/**
 * `kb.prune-embedding-cache` — 3-step refactor of
 * {@see \App\Console\Commands\PruneEmbeddingCacheCommand} with a
 * conditional approval gate for high-risk evictions.
 *
 * Steps:
 *   1. count-stale-embeddings    (dry-run-safe)
 *      Counts embedding_cache rows older than the cutoff. embedding_cache
 *      is intentionally cross-tenant (see EmbeddingCache class docblock)
 *      so the count is GLOBAL — not tenant-scoped.
 *   2. assess-eviction-risk      (control step, returns success OR paused)
 *      Compares the planned count against
 *      `config('kb.embedding_cache.approval_threshold', 5000)`.
 *      Behaviour:
 *       - planned ≤ threshold → success(approval_required=false). The
 *         eviction step runs immediately.
 *       - planned > threshold → paused(approval_required=true). The
 *         engine issues an approval token; the run sits at status=paused
 *         until an operator calls Flow::resume($token). After resume the
 *         eviction step runs.
 *      Why a custom step instead of FlowDefinitionBuilder::approvalGate():
 *      the built-in gate ALWAYS pauses. We want auto-pass for the common
 *      low-risk case (small daily evictions) and pause-for-review only
 *      on large evictions — that conditional decision lives in the step,
 *      not in the definition.
 *   3. evict-embedding-cache     (mutates DB)
 *      Calls EmbeddingCacheService::prune($cutoff). No compensator: a
 *      compensator would need a per-row pre-delete snapshot of every
 *      evicted vector, which defeats the cache's "throw it away, the
 *      provider rebuilds it on next miss" semantics.
 */
final class PruneEmbeddingCacheFlow
{
    public const NAME = 'kb.prune-embedding-cache';

    public const ASSESS_STEP = 'assess-eviction-risk';

    public static function register(FlowEngine $engine): void
    {
        $engine->define(self::NAME)
            ->withInput([
                // R30/R31 — tenant_id rides the input bag for audit
                // traceability even though the cache itself is global.
                'tenant_id',
                'cutoff_iso',
            ])
            ->step('count-stale-embeddings', CountStaleEmbeddingsStep::class)
                ->withDryRun(true)
            ->step(self::ASSESS_STEP, AssessEmbeddingEvictionRiskStep::class)
                ->withDryRun(true)
            ->step('evict-embedding-cache', EvictEmbeddingCacheStep::class)
            ->register();
    }
}
