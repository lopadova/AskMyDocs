<?php

declare(strict_types=1);

namespace App\Flow\Definitions;

use App\Flow\Steps\Graph\CountCanonicalDocumentsStep;
use App\Flow\Steps\Graph\DispatchCanonicalIndexerFanOutStep;
use App\Flow\Steps\Graph\TruncateGraphScopeStep;
use Padosoft\LaravelFlow\FlowEngine;

/**
 * `kb.rebuild-graph` — 3-step refactor of
 * {@see \App\Console\Commands\KbRebuildGraphCommand}.
 *
 * Steps:
 *   1. count-canonical-documents   (dry-run-safe)
 *      Tenant-scoped count of canonical KnowledgeDocument rows. Used by
 *      the CLI to short-circuit early when the tenant has zero canonical
 *      docs (no truncate, no dispatch).
 *   2. truncate-graph-scope        (mutates DB; skipped when --no-truncate)
 *      Wipes kb_edges + kb_nodes for the tenant (and project_key when
 *      provided) inside a single transaction. Edges deleted explicitly
 *      first so the cascade order is deterministic across drivers.
 *      No compensator — see TruncateGraphScopeStep docblock for the
 *      "source-of-truth is the markdown, just re-run on failure" rationale.
 *   3. dispatch-canonical-indexer  (mutates queue/DB)
 *      Walks the tenant's canonical documents with chunkById(100) and
 *      dispatches one CanonicalIndexerJob per row. Uses dispatchRebuild()
 *      so the engine-level idempotency cache is bypassed (a truncate
 *      followed by a same-version re-dispatch must re-execute, otherwise
 *      kb_nodes/kb_edges stay empty).
 *
 * Idempotency: the CLI builds a deterministic-but-fresh idempotencyKey
 * per invocation (`rebuild-graph:{tenant}:{project}:{hrtime}`) so
 * re-runs after a truncate ALWAYS re-execute. Without the hrtime nonce
 * the engine's per-(name, key) dedup would short-circuit the second
 * run and leave the graph empty.
 *
 * Tenant fan-out: the CLI iterates DISTINCT tenant_ids that have at
 * least one canonical doc and dispatches ONE Flow execute call per
 * tenant.
 */
final class RebuildGraphFlow
{
    public const NAME = 'kb.rebuild-graph';

    public static function register(FlowEngine $engine): void
    {
        $engine->define(self::NAME)
            ->withInput([
                'tenant_id',
            ])
            ->step('count-canonical-documents', CountCanonicalDocumentsStep::class)
                ->withDryRun(true)
            ->step('truncate-graph-scope', TruncateGraphScopeStep::class)
            ->step('dispatch-canonical-indexer', DispatchCanonicalIndexerFanOutStep::class)
            ->register();
    }
}
