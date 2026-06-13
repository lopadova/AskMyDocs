<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiCompiler;
use App\Services\Kb\AutoWiki\AutoWikiGate;
use App\Services\Kb\AutoWiki\AutoWikiGraphLinker;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.11/P1 — async Auto-Wiki frontmatter enrichment of a document.
 *
 * Dispatched by {@see IngestDocumentJob} AFTER the ingest flow has persisted
 * the document + chunks (the compiler reads chunks). Gated by
 * {@see AutoWikiGate} (config → tenant '*' → project, default-ON, R43). Idempotent
 * per content version: a doc whose current `version_hash` was already compiled
 * (recorded in `frontmatter_json._autowiki.source_version_hash`) is skipped, so
 * re-dispatch / requeue never re-spends LLM tokens on unchanged content.
 */
final class AutoWikiCompilerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Best-effort enrichment: tries=1, no backoff. handle() swallows compile
    // failures (see the catch), so a custom retry policy would be misleading —
    // a transient failure simply leaves this version un-enriched and the NEXT
    // ingest (new version_hash) re-dispatches. Rethrowing to use queue retries
    // is NOT viable here: under the test sync queue this job runs INLINE after
    // ingest, so a throw would break the already-committed ingest (the sibling
    // AnalyzeDocumentChangeJob swallows for the same reason).
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $documentId,
        public readonly string $tenantId,
    ) {
        $this->onQueue(config('kb.autowiki.queue', 'default'));
    }

    public function handle(
        TenantContext $tenants,
        AutoWikiCompiler $compiler,
        AutoWikiGate $gate,
        AutoWikiGraphLinker $linker,
    ): void {
        $previousTenant = $tenants->current();
        try {
            $tenants->set($this->tenantId);

            $document = KnowledgeDocument::query()
                ->forTenant($this->tenantId)
                ->find($this->documentId);
            if ($document === null) {
                return; // doc deleted between dispatch and run
            }

            if (! $gate->allows($this->tenantId, (string) $document->project_key, (bool) $document->is_canonical)) {
                return;
            }
            if (self::isVersionAlreadyCompiled($document)) {
                return; // idempotent — this content version is already enriched
            }

            try {
                $result = $compiler->compile($document);
                // P2 — once the auto tier is enriched, materialise its
                // cross-references into the navigable graph (nodes + inferred
                // edges). Re-load so the linker sees the freshly-applied
                // _autowiki block. Same best-effort envelope as compile().
                if (($result['applied'] ?? false) === true) {
                    $fresh = KnowledgeDocument::query()->forTenant($this->tenantId)->find($this->documentId);
                    if ($fresh !== null) {
                        $linker->link($fresh);
                    }
                }
            } catch (Throwable $e) {
                // Best-effort, swallow-and-log: under the test sync queue this
                // job runs INLINE right after the ingest flow, so a rethrow would
                // break the already-committed ingest. Auto-wiki enrichment is
                // non-critical metadata — a transient LLM/network failure simply
                // leaves this version un-enriched; the next ingest (new
                // version_hash) re-dispatches. (Handled "no usable output" returns
                // applied=false inside compile() without throwing.) Log the
                // exception object so Laravel records the class + stack trace.
                Log::warning('AutoWikiCompilerJob: compile failed (best-effort, skipped)', [
                    'document_id' => $this->documentId,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        } finally {
            $tenants->set($previousTenant);
        }
    }

    /**
     * Skip when the doc's CURRENT version was already auto-compiled — keyed on
     * the content version_hash, not time, so a new version always re-compiles
     * and an unchanged one never does. Public + static so it can be unit-tested
     * without invoking the ShouldQueue handler machinery.
     */
    public static function isVersionAlreadyCompiled(KnowledgeDocument $document): bool
    {
        $frontmatter = is_array($document->frontmatter_json) ? $document->frontmatter_json : [];
        // Guard the nested access: a manually-corrupted `_autowiki` that isn't an
        // array (e.g. a string) would make `['source_version_hash']` throw — so a
        // bad edit can never crash the gate; treat it as "not compiled" → re-run.
        $autowiki = $frontmatter['_autowiki'] ?? null;
        if (! is_array($autowiki)) {
            return false;
        }
        $compiledHash = $autowiki['source_version_hash'] ?? null;

        return $compiledHash !== null && $compiledHash === $document->version_hash;
    }
}
