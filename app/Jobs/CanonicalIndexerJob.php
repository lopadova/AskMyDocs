<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Flow\Definitions\CanonicalIndexFlow;
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
 * Idempotency: the inner steps still rely on the outgoing-edges replace
 * pattern + KbNode unique constraint to converge under concurrent re-runs.
 * Re-dispatching the same document_id under the same tenant returns the
 * existing FlowRun via the engine's idempotency key.
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
     * @param  string  $tenantId  Captured at dispatch time so the queue
     *                            worker can re-bind TenantContext before
     *                            any tenant-aware Eloquent query runs.
     *                            Defaults to 'default' for BC with legacy
     *                            tests + pre-PR-#115 dispatch sites.
     */
    public function __construct(
        public readonly int $documentId,
        public readonly string $tenantId = 'default',
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
                    // Intentionally NO idempotencyKey: the canonical
                    // indexer must re-execute every time it's dispatched
                    // (frontmatter changes, doc content changes, manual
                    // rebuild). The inner steps are idempotent at the
                    // DB layer (replaceEdgesFor wipes the outgoing set,
                    // KbNode::firstOrCreate dedupes targets) so re-runs
                    // converge correctly even under concurrent dispatches.
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
}
