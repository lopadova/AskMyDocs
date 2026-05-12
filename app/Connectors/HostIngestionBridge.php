<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Jobs\IngestDocumentJob;
use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;

/**
 * v4.6 — AskMyDocs host implementation of {@see ConnectorIngestionContract}.
 *
 * Connector composer packages (`padosoft/askmydocs-connector-*`) stay
 * standalone-agnostic by design: they never reference
 * `App\Jobs\IngestDocumentJob`, `App\Services\Kb\DocumentDeleter`, or
 * the host's `RedactorEngine` directly. Instead they resolve
 * {@see ConnectorIngestionContract} from the container and call into
 * the five framework methods. This class is the host's single
 * implementation of that contract — bound as a singleton in
 * {@see \App\Providers\AppServiceProvider::register()}.
 *
 * Five responsibilities (R30 tenant scoping is enforced inside every
 * method that touches `knowledge_documents` / `kb_canonical_audit` /
 * the queue dispatcher):
 *
 *   1. {@see dispatchIngestion()} — hands the freshly-written document
 *      off to {@see IngestDocumentJob} with the tenant captured at
 *      dispatch time (the worker rebinds it inside `handle()`).
 *   2. {@see resolveKbSourcePath()} — translates a relative path into
 *      `{relative, absolute, disk}` honouring `KB_FILESYSTEM_DISK` +
 *      `KB_PATH_PREFIX`. Pass-through to {@see KbPath::normalize()} so
 *      the connector + ingest job + delete sweep all see the same
 *      canonical form (R1).
 *   3. {@see redactContent()} — R26 PII redaction at the ingest
 *      boundary. Honours `kb.pii_redactor.enabled` and
 *      `kb.pii_redactor.redact_before_ingest` (defaults: off-off so
 *      hosts opt-in explicitly).
 *   4. {@see emitAudit()} — writes one row to `kb_canonical_audit`
 *      with `event_type='connector_<eventType>'` so the immutable
 *      forensic trail survives hard deletes (R10 / Section 4 — the
 *      table has no FK to `knowledge_documents`).
 *   5. {@see softDeleteByRemoteId()} — looks up
 *      `knowledge_documents.metadata->$metadataKey == $remoteId`
 *      tenant-scoped, then routes through {@see DocumentDeleter::delete()}
 *      with `force=false` so the row joins the soft-delete retention
 *      window and the prune job hard-deletes it later. Already-trashed
 *      rows are skipped (idempotent under repeated incremental sync).
 */
final class HostIngestionBridge implements ConnectorIngestionContract
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentDeleter $deleter,
    ) {}

    public function dispatchIngestion(
        string $projectKey,
        string $relativePath,
        string $disk,
        string $title,
        array $metadata,
        string $mimeType,
        string $tenantId,
    ): void {
        // The connector resolved the active tenant before calling here;
        // we pass it through to IngestDocumentJob's $tenantId so the
        // queue worker rebinds the same tenant before BelongsToTenant
        // auto-fills any new rows. Never read TenantContext::current()
        // here — the dispatcher's process may belong to a different
        // tenant by the time this runs in a long-lived queue worker.
        IngestDocumentJob::dispatch(
            projectKey: $projectKey,
            relativePath: $relativePath,
            disk: $disk,
            title: $title,
            metadata: $metadata,
            mimeType: $mimeType,
            tenantId: $tenantId,
        );
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        // R1 — canonical normalisation. Throws InvalidArgumentException
        // on empty / traversal-bearing input so connector code paths
        // surface bad input as a 4xx-ish failure rather than silently
        // landing on the wrong disk.
        $normalised = KbPath::normalize($relativePath);

        $disk = (string) config('kb.sources.disk', 'kb');
        $prefix = (string) config('kb.sources.path_prefix', '');
        $prefix = trim($prefix, '/');

        $absolute = $prefix === ''
            ? $normalised
            : $prefix.'/'.$normalised;

        return [
            'relative' => $normalised,
            'absolute' => $absolute,
            'disk' => $disk,
        ];
    }

    public function redactContent(string $content): string
    {
        if (! (bool) config('kb.pii_redactor.enabled', false)) {
            return $content;
        }

        if (! (bool) config('kb.pii_redactor.redact_before_ingest', false)) {
            return $content;
        }

        /** @var RedactorEngine $engine */
        $engine = app(RedactorEngine::class);
        $strategy = app(MaskStrategy::class);

        return $engine->redact($content, $strategy);
    }

    public function emitAudit(
        string $connectorKey,
        string $eventType,
        ?int $installationId = null,
        ?array $metadata = null,
    ): void {
        // Auto-namespace the event type so a "sync_completed" event
        // from the Notion connector is distinguishable from any
        // host-side "sync_completed" event in the same audit table.
        $namespaced = str_starts_with($eventType, 'connector_')
            ? $eventType
            : 'connector_'.$eventType;

        $payload = [
            'connector_key' => $connectorKey,
            'installation_id' => $installationId,
            'metadata' => $metadata,
        ];

        try {
            // `project_key` is NOT NULL on the kb_canonical_audit
            // schema. Connector events aren't tied to a specific KB
            // project — they describe an installation-level event — so
            // we stamp `connector` as a sentinel project. This keeps
            // the audit table queryable by project (existing canonical
            // workflow filters by project_key) without forcing
            // connector events to attach to an arbitrary KB project.
            KbCanonicalAudit::create([
                'tenant_id' => $this->tenantContext->current(),
                'project_key' => 'connector',
                'doc_id' => null,
                'slug' => null,
                'event_type' => $namespaced,
                'actor' => 'connector:'.$connectorKey,
                'before_json' => null,
                'after_json' => null,
                'metadata_json' => $payload,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must NEVER break the user-facing connector path.
            // Mirror ChatLogManager::log()'s try/catch posture (CLAUDE.md
            // §6 "Logging never breaks the user path") — log the failure
            // and move on.
            Log::warning('HostIngestionBridge::emitAudit failed', [
                'connector_key' => $connectorKey,
                'event_type' => $namespaced,
                'installation_id' => $installationId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function softDeleteByRemoteId(
        ConnectorInstallation $installation,
        string $metadataKey,
        string $remoteId,
    ): bool {
        // R30 — installation row carries the tenant_id; we never
        // trust the active TenantContext when the caller already
        // supplied an authoritative tenant id on the installation.
        $tenantId = (string) $installation->tenant_id;

        // metadata is stored as JSON; portable JSON query for SQLite +
        // PostgreSQL via Eloquent's `->`-arrow nested key syntax.
        $documents = KnowledgeDocument::query()
            ->where('tenant_id', $tenantId)
            ->where("metadata->{$metadataKey}", $remoteId)
            ->get();

        if ($documents->isEmpty()) {
            return false;
        }

        $actedUpon = false;
        DB::transaction(function () use ($documents, &$actedUpon): void {
            foreach ($documents as $document) {
                if ($document->trashed()) {
                    // Idempotent — repeated incremental sweeps stop
                    // double-counting after the first sweep soft-deletes
                    // the row. The prune job hard-deletes later.
                    continue;
                }

                $this->deleter->delete($document, force: false);
                $actedUpon = true;
            }
        });

        return $actedUpon;
    }
}
