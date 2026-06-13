<?php

namespace App\Jobs;

use App\Flow\Definitions\IngestDocumentFlow;
use App\Support\Kb\SourceType;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;

/**
 * Ingests a single document of any supported format into the knowledge base.
 *
 * Dispatched by KbIngestFolderCommand (folder walker) and KbIngestController
 * (remote HTTP ingestion). v4.2/W2 — `handle()` is now a thin wrapper that
 * dispatches the {@see IngestDocumentFlow} saga synchronously via
 * {@see Flow::execute()}. The Flow definition is the source-of-truth for
 * the parse → chunk → embed → persist → maybe-dispatch-canonical pipeline,
 * and a compensator on the persist step rolls back the document if any
 * downstream step fails.
 *
 * Retry semantics are preserved: $tries=3, backoff=[10,30,60] still apply
 * because Flow::execute() runs in-process and any uncaught failure
 * (including post-compensation) bubbles back to this queued worker, which
 * re-queues the job per Laravel's normal retry rules.
 *
 * Back-compat: when `$mimeType` is null (legacy callers from before T1.8)
 * defaults to `text/markdown`, mirroring the pre-Flow behaviour.
 */
class IngestDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int,int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $projectKey,
        public readonly string $relativePath,
        public readonly string $disk,
        public readonly ?string $title = null,
        public readonly array $metadata = [],
        // T1.8 — optional MIME override. When omitted, defaults to
        // `text/markdown` (legacy back-compat for jobs queued before T1.8).
        public readonly ?string $mimeType = null,
        // PR #115 review iteration 1 — tenant_id is captured at DISPATCH
        // time (in the dispatcher's request/CLI process) and serialised
        // onto the queued payload. Reading TenantContext inside handle()
        // is wrong: queue workers boot a fresh container with the
        // default tenant, so any non-default tenant would silently
        // ingest into 'default'. Defaults to 'default' so callers that
        // never set a tenant — and existing tests — keep working.
        public readonly string $tenantId = 'default',
    ) {
        $this->onQueue(config('kb.ingest.queue', 'kb-ingest'));
    }

    /**
     * Factory that captures the active TenantContext at dispatch time and
     * forwards it to the constructor. Use this from every HTTP / CLI / job
     * call site so the queue worker re-binds the right tenant inside
     * handle(). The plain `dispatch()` path stays BC for tests that
     * explicitly drive tenant context themselves.
     *
     * @param  array<string,mixed>  $metadata
     */
    public static function dispatchForCurrentTenant(
        string $projectKey,
        string $relativePath,
        string $disk,
        ?string $title = null,
        array $metadata = [],
        ?string $mimeType = null,
    ): \Illuminate\Foundation\Bus\PendingDispatch {
        $tenantId = app(TenantContext::class)->current();

        return self::dispatch(
            projectKey: $projectKey,
            relativePath: $relativePath,
            disk: $disk,
            title: $title,
            metadata: $metadata,
            mimeType: $mimeType,
            tenantId: $tenantId,
        );
    }

    public function handle(TenantContext $tenantContext): void
    {
        // PR #115 review iteration 2 (R30) — capture the previous tenant
        // BEFORE we mutate the singleton and ALWAYS restore it in `finally`.
        // Queue workers (Horizon + plain Laravel queue) are long-lived: a
        // single PHP process drains many jobs in sequence. TenantContext is
        // a container singleton, so without restore-on-exit, job A's tenant
        // bleeds into job B's worker boot — a cross-tenant write hazard via
        // BelongsToTenant's `creating` auto-fill.
        $previousTenant = $tenantContext->current();
        try {
            // PR #115 review iteration 1 — re-bind TenantContext FIRST in the
            // worker process from the property captured at dispatch time. All
            // downstream code in the saga (Flow steps, DocumentIngestor,
            // BelongsToTenant trait) reads the active tenant via
            // TenantContext::current(); without this re-bind every tenant-
            // aware insert would silently land under 'default'.
            $tenantContext->set($this->tenantId);

            $title = $this->title ?: pathinfo($this->relativePath, PATHINFO_FILENAME);
            $mimeType = $this->mimeType ?? 'text/markdown';

            $run = Flow::execute(
                IngestDocumentFlow::NAME,
                [
                    // R30/R31 — the tenant_id rides along the input bag so each
                    // step can re-bind it on the request-scoped TenantContext
                    // before any tenant-aware Eloquent query runs. We use the
                    // PROPERTY rather than TenantContext::current() so the
                    // value matches the captured-at-dispatch tenant, even if
                    // some other code mutated the context after handle() ran
                    // its set() above.
                    'tenant_id' => $this->tenantId,
                    'project_key' => $this->projectKey,
                    'source_path' => $this->relativePath,
                    'disk' => $this->disk,
                    'title' => $title,
                    'metadata' => $this->metadata,
                    'mime_type' => $mimeType,
                ],
                FlowExecutionOptions::make(
                    // Tenant-scoped idempotency. version_hash isn't available
                    // pre-read; the inner DocumentIngestor::findExistingVersion()
                    // handles content-level dedup, so re-dispatching the same
                    // path under the same tenant short-circuits at the engine
                    // level (existing FlowRun returned).
                    idempotencyKey: $this->buildIdempotencyKey($this->tenantId),
                    correlationId: $this->tenantId,
                ),
            );

            // Engine status taxonomy: succeeded | failed | compensated |
            // aborted | paused. Anything other than `succeeded` means the
            // saga did not complete, so we re-throw to drive Laravel's
            // $tries / backoff retry semantics.
            if ($run->status !== \Padosoft\LaravelFlow\FlowRun::STATUS_SUCCEEDED) {
                $failedStep = $run->failedStep ?? '(unknown)';
                throw new \RuntimeException(
                    "IngestDocumentFlow [{$run->status}] at step [{$failedStep}] for {$this->disk}:{$this->relativePath}"
                );
            }

            $persistResult = $run->stepResults['persist-chunks'] ?? null;
            $documentId = $persistResult instanceof \Padosoft\LaravelFlow\FlowStepResult
                ? ($persistResult->output['knowledge_document_id'] ?? null)
                : null;

            Log::info('IngestDocumentJob completed', [
                'document_id' => $documentId,
                'flow_run_id' => $run->id,
                'project_key' => $this->projectKey,
                'source_path' => $this->relativePath,
                'source_type' => SourceType::fromMime($mimeType)->value,
                'disk' => $this->disk,
            ]);

            // v8.7/W3–W4 — dispatch the async AI deep-analysis now that the
            // document + chunks are committed (the analyzer reads chunks, so
            // it must run AFTER the flow's persist step, not from the
            // `KnowledgeDocument::created` hook which fires before chunks
            // exist). The job is itself config-gated (canonical-default ON /
            // non-canonical opt-in) + debounced, so dispatching
            // unconditionally here is cheap — the gate lives in one place.
            if ($documentId !== null && (bool) config('kb.change_analysis.enabled', true)) {
                \App\Jobs\AnalyzeDocumentChangeJob::dispatch((int) $documentId, $this->tenantId);
            }

            // v8.11/P1 — dispatch the async Auto-Wiki frontmatter enrichment
            // (tags/summary/aliases/cross-refs into the auto tier). Like the
            // change-analysis job it is itself gated (AutoWikiGate) + version-
            // idempotent, so dispatching unconditionally here is cheap. Default-ON
            // (R43): KB_AUTOWIKI_ENABLED=false → no dispatch, behaviour unchanged.
            if ($documentId !== null && (bool) config('kb.autowiki.enabled', true)) {
                \App\Jobs\AutoWikiCompilerJob::dispatch((int) $documentId, $this->tenantId);
            }
        } finally {
            // Restore even on exception/throw so a failing job never leaves
            // the singleton stuck on this job's tenant for the next one.
            $tenantContext->set($previousTenant);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('IngestDocumentJob failed after retries', [
            'project_key' => $this->projectKey,
            'source_path' => $this->relativePath,
            'disk' => $this->disk,
            'error' => $exception->getMessage(),
        ]);
    }

    private function buildIdempotencyKey(string $tenantId): string
    {
        // FlowExecutionOptions enforces ≤ 255 characters for the key.
        // tenant_id (≤ 50) + ":" + project_key (often ≤ 64) + ":" +
        // source_path can exceed that limit on long deeply-nested paths,
        // so for safety we hash the tail beyond a comfortable plain
        // prefix and surface a fixed-length key. The hash is content-
        // agnostic (path-only) so tenant + project + path uniquely
        // identify the row regardless of file bytes.
        $raw = "{$tenantId}:{$this->projectKey}:{$this->relativePath}";
        if (strlen($raw) <= 200) {
            return $raw;
        }
        return "{$tenantId}:{$this->projectKey}:".hash('sha256', $this->relativePath);
    }
}
