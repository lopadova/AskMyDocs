<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Flow\Definitions\CanonicalIndexFlow;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;

/**
 * Populates {@see \App\Models\KbNode} and {@see \App\Models\KbEdge} from a
 * canonical document's frontmatter + chunk wikilinks.
 *
 * v4.2/W2 (PR #116) — `handle()` is a thin wrapper that runs the
 * {@see CanonicalIndexFlow} saga synchronously via {@see Flow::execute()}.
 * The Flow definition is the source-of-truth for the load → populate-nodes
 * → populate-edges pipeline; a compensator on `populate-nodes` rolls back
 * exactly the KbNode rows the failed run inserted (FK cascade also
 * removes any partially-inserted edges).
 *
 * Idempotency: dispatched with an engine-level idempotencyKey of
 * `canonical-index:{tenantId}:{documentId}:{versionHash}`, mirroring
 * {@see \App\Jobs\IngestDocumentJob}'s pattern. Two important properties
 * fall out of including version_hash in the key:
 *
 *   - re-ingest with NEW content lands as a new (or updated) row whose
 *     version_hash changes => the indexer re-executes naturally;
 *   - re-dispatching the SAME (tenant, document_id, version_hash) short-
 *     circuits at the engine level (existing FlowRun returned, no re-run),
 *     preventing concurrent indexer runs against the same content.
 *
 * Iteration 5 (PR #116) — `kb:rebuild-graph` after a graph truncate must
 * still be able to FORCE re-execution against unchanged content. The
 * `$forceReindex` ctor flag (and the `dispatchRebuild()` factory) appends
 * a unix-millis nonce to the idempotency key so the engine sees a fresh
 * key and re-runs the saga. Default callers (regular ingest path) keep
 * idempotency.
 *
 * The inner steps are still independently idempotent (replaceEdgesFor()
 * wipes the outgoing set; KbNode::firstOrCreate() dedupes target nodes),
 * so a forced re-run converges correctly even on a populated graph.
 */
class CanonicalIndexerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * @param  string  $tenantId      Captured at dispatch time so the queue
     *                                worker can re-bind TenantContext before
     *                                any tenant-aware Eloquent query runs.
     *                                Defaults to 'default' for BC with legacy
     *                                tests + pre-PR-#115 dispatch sites.
     * @param  bool    $forceReindex  When true, the idempotency key is
     *                                salted with a unix-millis nonce so the
     *                                engine bypasses dedup and re-executes
     *                                the saga even against unchanged
     *                                content. Used by `kb:rebuild-graph`
     *                                after a graph truncate. Default false:
     *                                regular ingest dispatchers keep dedup.
     */
    public function __construct(
        public readonly int $documentId,
        public readonly string $tenantId = 'default',
        public readonly bool $forceReindex = false,
    ) {
        $this->onQueue(config('kb.ingest.queue', 'kb-ingest'));
    }

    public function handle(?TenantContext $tenantContext = null): void
    {
        // Resolve from the container when invoked outside the queue worker
        // (e.g. legacy unit tests that instantiate the job and call handle()
        // directly). Inside the worker Laravel injects the container-bound
        // singleton automatically.
        $tenantContext ??= app(TenantContext::class);

        // PR #115 review iteration 2 (R30) — capture the previous tenant
        // BEFORE we mutate the singleton and ALWAYS restore in `finally`.
        // Long-lived queue workers drain many jobs per PHP boot; without
        // restore-on-exit, this job's tenant bleeds into the next job's
        // worker context.
        $previousTenant = $tenantContext->current();
        try {
            $tenantContext->set($this->tenantId);

            $run = Flow::execute(
                CanonicalIndexFlow::NAME,
                [
                    'tenant_id' => $this->tenantId,
                    'document_id' => $this->documentId,
                ],
                FlowExecutionOptions::make(
                    // E.2 + iter5 — tenant + version-scoped idempotency
                    // key. New content lands as a row whose version_hash
                    // changes => new key => natural re-execution. Same
                    // content + same id => same key => engine returns
                    // existing FlowRun (concurrent re-dispatch dedup).
                    // The forceReindex flag salts the key with a
                    // unix-millis nonce so `kb:rebuild-graph` can re-run
                    // the saga against unchanged content after a graph
                    // truncate. The inner steps remain idempotent at the
                    // DB layer (replaceEdgesFor wipes the outgoing set,
                    // KbNode::firstOrCreate dedupes targets) so a forced
                    // re-run under a fresh idempotency window converges.
                    idempotencyKey: $this->buildIdempotencyKey(),
                    correlationId: $this->tenantId,
                ),
            );

            // Engine status taxonomy: succeeded | failed | compensated |
            // aborted | paused. Anything other than `succeeded` means the
            // saga did not complete — re-throw to drive Laravel's retry
            // semantics ($tries=3).
            if ($run->status !== FlowRun::STATUS_SUCCEEDED) {
                $failedStep = $run->failedStep ?? '(unknown)';
                throw new \RuntimeException(
                    "CanonicalIndexFlow [{$run->status}] at step [{$failedStep}] for document [{$this->documentId}]"
                );
            }

            Log::info('CanonicalIndexerJob completed', [
                'document_id' => $this->documentId,
                'flow_run_id' => $run->id,
                'tenant_id' => $this->tenantId,
            ]);
        } finally {
            // Restore even on exception/throw.
            $tenantContext->set($previousTenant);
        }
    }

    /**
     * Factory mirroring IngestDocumentJob::dispatchForCurrentTenant() —
     * captures the active TenantContext at dispatch time. Use this from
     * every HTTP / CLI / saga call site; the plain `dispatch()` path stays
     * BC for tests that drive tenant context themselves.
     */
    public static function dispatchForCurrentTenant(int $documentId): \Illuminate\Foundation\Bus\PendingDispatch
    {
        $tenantId = app(TenantContext::class)->current();
        return self::dispatch($documentId, $tenantId);
    }

    /**
     * Iter5 (PR #116) — explicit "forced re-execution" entrypoint for
     * `kb:rebuild-graph`. Sets the forceReindex flag so the engine-level
     * idempotency key gets a unix-millis nonce and the saga re-runs even
     * when (tenant, document_id, version_hash) is unchanged.
     */
    public static function dispatchRebuild(int $documentId, ?string $tenantId = null): \Illuminate\Foundation\Bus\PendingDispatch
    {
        $tenantId ??= app(TenantContext::class)->current();
        return self::dispatch($documentId, $tenantId, true);
    }

    /**
     * Build the engine-level idempotency key. Includes version_hash so
     * a re-ingest with new content naturally re-runs the saga; the
     * forceReindex flag appends a unix-millis nonce so operator-driven
     * rebuilds (after graph truncate) bypass dedup.
     *
     * version_hash is read from the row at dispatch time. If the row no
     * longer exists (rare race: doc hard-deleted between dispatch and
     * handle), we fall back to a deterministic 'missing' marker — the
     * inner step then no-ops the doc anyway.
     */
    public function buildIdempotencyKey(): string
    {
        $versionHash = (string) (KnowledgeDocument::query()
            ->whereKey($this->documentId)
            ->value('version_hash') ?? 'missing');

        $base = "canonical-index:{$this->tenantId}:{$this->documentId}:{$versionHash}";
        if (! $this->forceReindex) {
            return $base;
        }

        // Use hrtime() to dodge same-millisecond collisions when two
        // forced rebuilds for the same doc fan out from the same loop.
        $nonce = (string) hrtime(true);
        return "{$base}:rebuild-{$nonce}";
    }
}
