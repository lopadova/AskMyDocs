<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiCompiler;
use App\Services\Kb\AutoWiki\AutoWikiGate;
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

    public int $tries = 2;

    public int $timeout = 120;

    /** @var array<int,int> */
    public array $backoff = [30, 120];

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
                $compiler->compile($document);
            } catch (Throwable $e) {
                // R14 — never let an enrichment failure break the ingest path;
                // log loudly so it's visible, don't crash the worker.
                Log::warning('AutoWikiCompilerJob: compile failed', [
                    'document_id' => $this->documentId,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
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
        $compiledHash = $frontmatter['_autowiki']['source_version_hash'] ?? null;

        return $compiledHash !== null && $compiledHash === $document->version_hash;
    }
}
