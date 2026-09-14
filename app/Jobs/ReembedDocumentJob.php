<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Scopes\AccessScopeScope;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use League\Flysystem\UnableToReadFile;
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

    /**
     * The re-embed IS done — row, chunks and embeddings committed and
     * correct; only the conversion artifact behind the pointer is missing
     * (state `missing`, repaired by the next identical ingest or
     * `kb:artifacts-backfill`). A retry here would not repair it:
     * `forceReembed` replaces the chunk set again instead of taking the
     * same-hash repair path, and three retries would then report a failed
     * re-embed for a document that was re-embedded. Logged, not failed
     * (ADR 0030 §3).
     */
    private function logArtifactNotPublished(\App\Services\Kb\Versioning\ArtifactPublishFailedException $e): void
    {
        Log::warning('ReembedDocumentJob: document re-embedded but its conversion artifact could not be published; kb:artifacts-backfill or the next identical ingest repairs it', [
            'document_id' => $e->documentId,
            'disk' => $e->disk,
            'markdown_path' => $e->markdownPath,
            'tenant_id' => $this->tenantId,
            'error' => $e->getMessage(),
        ]);
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

            $metadata = is_array($document->metadata) ? $document->metadata : [];
            // The source is read from the namespace the row RECORDED
            // (`metadata.disk` / `metadata.prefix`), the connector's current
            // one only for a legacy row: after a disk or prefix change the
            // current path may hold ANOTHER file, and re-embedding those bytes
            // would mint a new version instead of re-deriving this one.
            $resolved = $this->resolveSourceFor($document, $metadata);

            // Swallow ONLY the missing-file case (Storage::get may return null OR
            // throw, depending on the disk's `throw` config / driver) — a logged
            // skip, no TOCTOU exists()+get() race. REAL I/O failures (permissions,
            // a transient storage outage) propagate so the job retries instead of
            // silently leaving stale chunks.
            try {
                $bytes = Storage::disk($resolved['disk'])->get($resolved['absolute']);
            } catch (FileNotFoundException|UnableToReadFile $e) {
                $bytes = null;
            }
            if ($bytes === '') {
                // A zero-byte read is not a source: it takes the same branch
                // as a missing one (the stored artifact, or a logged skip) —
                // never an empty replay that replaces the valid chunks.
                $bytes = null;
            }

            // v8.36 / ADR 0030 — `markdown_only` retention drops the original
            // after the artifact commit: the stored artifact IS the converted
            // Markdown of this version, so it is re-chunked and re-embedded
            // WITHOUT a converter (the row's mime may be a binary format the
            // converter would choke on, or OCR again) and only when its bytes
            // still hash to the version (R14: a corrupt artifact is a logged
            // skip, never a new version).
            $artifactPath = $document->markdown_path;
            if ($bytes === null && is_string($artifactPath) && $artifactPath !== '') {
                // The artifact lives on the same recorded disk.
                $artifact = app(\App\Services\Kb\Versioning\ConversionArtifactStore::class)->read($resolved['disk'], $artifactPath);
                if ($artifact !== null) {
                    $expected = (string) ($document->content_hash ?? $document->document_hash);
                    if (hash('sha256', $artifact) !== $expected) {
                        Log::warning('ReembedDocumentJob: stored artifact does not hash to the version; skipping re-embed.', [
                            'document_id' => $document->id,
                            'source_path' => $document->source_path,
                            'tenant_id' => $this->tenantId,
                        ]);

                        return;
                    }
                    // The same "done, artifact missing" outcome as the disk
                    // branch below: a refused publish is logged, never a
                    // failed job for a document that was re-embedded.
                    try {
                        $ingestor->reembedFromMarkdown($document, $artifact);
                    } catch (\App\Services\Kb\Versioning\ArtifactPublishFailedException $e) {
                        $this->logArtifactNotPublished($e);
                    }

                    return;
                }
            }

            if ($bytes === null) {
                Log::warning('ReembedDocumentJob: source markdown missing on disk; skipping re-embed.', [
                    'document_id' => $document->id,
                    'source_path' => $document->source_path,
                    'tenant_id' => $this->tenantId,
                ]);

                return;
            }

            try {
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
            } catch (\App\Services\Kb\Versioning\ArtifactPublishFailedException $e) {
                $this->logArtifactNotPublished($e);
            }
        } finally {
            $tenantContext->set($previousTenant);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{disk: string, absolute: string}
     */
    private function resolveSourceFor(KnowledgeDocument $document, array $metadata): array
    {
        $current = app(ConnectorIngestionContract::class)->resolveKbSourcePath((string) $document->source_path);
        if (! is_string($metadata['disk'] ?? null) || $metadata['disk'] === '') {
            return ['disk' => (string) $current['disk'], 'absolute' => (string) $current['absolute']];
        }
        $prefix = array_key_exists('prefix', $metadata)
            ? trim(str_replace('\\', '/', (string) $metadata['prefix']), '/')
            : trim((string) config('kb.sources.path_prefix', ''), '/');
        $relative = (string) $current['relative'];

        return ['disk' => (string) $metadata['disk'], 'absolute' => KbPath::normalize($prefix === '' ? $relative : $prefix.'/'.$relative)];
    }
}
