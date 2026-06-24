<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Scopes\AccessScopeScope;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;

/**
 * v8.23 (Ciclo 4, PR5) — re-embed ONE knowledge document from its disk source,
 * applying the CURRENT PII ingestion policy.
 *
 * Triggered after a `kb_pii_settings` policy change (via the tri-surface
 * `kb:reembed-project` / `POST /api/admin/pii/reembed` / `KbReembedProjectTool`),
 * so chunks + embeddings that were produced under the OLD policy are re-derived
 * under the new one. Uses `DocumentIngestor::ingest(forceReembed: true)`, which
 * bypasses the version_hash no-op (raw markdown is unchanged) and REPLACES the
 * document's chunk set.
 *
 * Tenant captured at dispatch + re-bound here (R30); a missing-on-disk source
 * degrades to a logged skip, never a crash.
 */
class ReembedDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int,int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $documentId,
        public readonly string $tenantId = 'default',
    ) {
        $this->onQueue(config('kb.ingest.queue', 'kb-ingest'));
    }

    public function handle(TenantContext $tenantContext, DocumentIngestor $ingestor): void
    {
        $previousTenant = $tenantContext->current();
        $tenantContext->set($this->tenantId);

        try {
            // Maintenance op: reach the doc regardless of per-project read ACL,
            // but stay tenant-scoped (R30). Only live (non-archived) rows.
            $document = KnowledgeDocument::query()
                ->withoutGlobalScope(AccessScopeScope::class)
                ->forTenant($this->tenantId)
                ->where('status', 'active')
                ->find($this->documentId);

            if ($document === null) {
                return; // deleted / archived since dispatch — nothing to do.
            }

            $resolved = app(ConnectorIngestionContract::class)->resolveKbSourcePath((string) $document->source_path);
            $bytes = Storage::disk($resolved['disk'])->get($resolved['absolute']);

            if ($bytes === null) {
                Log::warning('ReembedDocumentJob: source markdown missing on disk; skipping re-embed.', [
                    'document_id' => $document->id,
                    'source_path' => $document->source_path,
                    'tenant_id' => $this->tenantId,
                ]);

                return;
            }

            $metadata = is_array($document->metadata) ? $document->metadata : [];

            $ingestor->ingest(
                projectKey: (string) $document->project_key,
                source: new SourceDocument(
                    sourcePath: (string) $document->source_path,
                    mimeType: $document->mime_type !== null && $document->mime_type !== '' ? (string) $document->mime_type : 'text/markdown',
                    bytes: (string) $bytes,
                    externalUrl: null,
                    externalId: null,
                    connectorType: is_string($metadata['connector'] ?? null) ? $metadata['connector'] : 'local',
                    metadata: $metadata,
                ),
                title: (string) $document->title,
                forceReembed: true,
            );
        } finally {
            $tenantContext->set($previousTenant);
        }
    }
}
