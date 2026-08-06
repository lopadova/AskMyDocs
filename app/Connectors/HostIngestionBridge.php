<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Imap\ImapSyncProgressContext;
use App\Jobs\IngestDocumentJob;
use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Services\Demo\EmailDataset\EmailDatasetReader;
use App\Services\Demo\EmailDataset\FixtureMetadataIndex;
use App\Services\Kb\DocumentDeleter;
use App\Services\Kb\Pii\IngestStrategyResolver;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\PiiRedactor\RedactorEngine;
use RuntimeException;

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
        private readonly SyncRunContext $syncRunContext,
        private readonly ImapSyncProgressContext $imapSyncProgress,
        private readonly EmailDatasetReader $emailDatasetReader,
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
        $progressMetadata = $metadata;
        $metadata = $this->withGeneratedFixtureMetadata($projectKey, $metadata);
        $relativePath = $this->prepareImapSourcePath(
            projectKey: $projectKey,
            relativePath: $relativePath,
            disk: $disk,
            title: $title,
            metadata: $metadata,
        );
        $this->restoreGeneratedFixtureIfRolledBack(
            projectKey: $projectKey,
            relativePath: $relativePath,
            tenantId: $tenantId,
            metadata: $metadata,
        );

        IngestDocumentJob::dispatch(
            projectKey: $projectKey,
            relativePath: $relativePath,
            disk: $disk,
            title: $title,
            metadata: $metadata,
            mimeType: $mimeType,
            tenantId: $tenantId,
        );

        // v8.21 (Ciclo 2) — count this document against the connector sync run
        // currently executing in this worker (no-op outside a sync, e.g. the
        // HTTP/CLI ingest paths), so connector_sync_runs.items_discovered is
        // accurate without a package change.
        $this->syncRunContext->recordDispatch();

        // The progress context is active only inside an IMAP sync job. It
        // confirms a UID after the real host dispatch succeeded; every other
        // connector and direct ingestion path sees a no-op.
        $this->imapSyncProgress->recordSuccessfulDispatch($progressMetadata, $tenantId);
    }

    /**
     * The upstream IMAP connector currently ignores Storage::put()'s boolean
     * result and names files with the transport UID. The host closes both gaps
     * before acknowledging the UID:
     *
     *  - the selected KB disk must throw on write failures and the just-written
     *    source must exist;
     *  - generated fixtures are moved to a stable version+fixture path, so a
     *    purge/re-APPEND that assigns new IMAP UIDs cannot duplicate KB rows.
     *
     * Ordinary connectors retain their existing source-path contract.
     *
     * @param  array<string,mixed>  $metadata
     */
    private function prepareImapSourcePath(
        string $projectKey,
        string $relativePath,
        string $disk,
        string $title,
        array &$metadata,
    ): string {
        if (($metadata['connector'] ?? null) !== 'imap') {
            return $relativePath;
        }

        $resolved = $this->resolveKbSourcePath($relativePath);
        if ($resolved['disk'] !== $disk) {
            throw new RuntimeException(
                "IMAP source disk mismatch: connector used {$disk}, host resolved {$resolved['disk']}."
            );
        }

        if (config("filesystems.disks.{$disk}.throw") !== true) {
            throw new RuntimeException(
                "IMAP ingestion requires filesystems.disks.{$disk}.throw=true "
                .'so a failed source write cannot advance the UID checkpoint.'
            );
        }

        $filesystem = Storage::disk($disk);
        if (! $filesystem->exists($resolved['absolute'])) {
            throw new RuntimeException(
                "IMAP source was not persisted before dispatch: {$resolved['absolute']}."
            );
        }

        if (($metadata['generated_fixture'] ?? null) !== true) {
            return $resolved['relative'];
        }

        $datasetVersion = (string) $metadata['dataset_version'];
        $fixtureId = (string) $metadata['fixture_id'];
        $installationId = filter_var(
            $metadata['installation_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($installationId === false) {
            throw new RuntimeException('Generated IMAP fixture metadata has no valid installation_id.');
        }

        $mailbox = trim((string) ($metadata['imap_mailbox'] ?? ''));
        $mailboxSlug = Str::slug($mailbox) ?: 'folder';
        $base = sprintf(
            '%s/connectors/imap/installation-%d/%s/datasets/%s',
            $projectKey,
            $installationId,
            $mailboxSlug,
            $datasetVersion,
        );
        $isAttachment = isset($metadata['attachment_of_message_id']);
        if ($isAttachment) {
            throw new RuntimeException(
                'Generated fixture attachments are not accepted until the fixture index '
                .'declares a per-attachment content commitment.',
            );
        }
        $stableRelative = $isAttachment
            ? $base.'/'.$fixtureId.'/attachments/'.basename($resolved['relative'])
            : $base.'/'.$fixtureId.'.md';
        $stable = $this->resolveKbSourcePath($stableRelative);
        if ($stable['disk'] !== $disk) {
            throw new RuntimeException('Generated IMAP fixture resolved to a different KB disk.');
        }

        $contents = $filesystem->get($resolved['absolute']);
        if (! is_string($contents)) {
            throw new RuntimeException(
                "Unable to read generated IMAP source {$resolved['absolute']} for stable publication."
            );
        }
        $this->verifyGeneratedSourceCommitment($title, $contents, $metadata);

        if ($stable['absolute'] !== $resolved['absolute']) {
            if (! $filesystem->put($stable['absolute'], $contents)) {
                throw new RuntimeException(
                    "Unable to publish generated IMAP source {$stable['absolute']}."
                );
            }
            if (! $filesystem->delete($resolved['absolute'])) {
                throw new RuntimeException(
                    "Unable to remove transient UID-based IMAP source {$resolved['absolute']}."
                );
            }
        }

        // Reconciliation in the vendor package works with ephemeral
        // mailbox:uidvalidity:uid keys. Generated datasets have their own
        // version-scoped lifecycle, so keep that transport key for diagnostics
        // and expose a stable key to the persisted knowledge document.
        $metadata['imap_transport_doc_key'] = $metadata['imap_doc_key'] ?? null;
        $metadata['imap_doc_key'] = 'fixture:'.$datasetVersion.':'.$fixtureId;

        return $stable['relative'];
    }

    /**
     * Prevents a reused/spoofed fixture Message-ID from replacing the stable
     * source with bytes that do not belong to the immutable dataset record.
     *
     * @param  array<string,mixed>  $metadata
     */
    private function verifyGeneratedSourceCommitment(
        string $title,
        string $markdown,
        array $metadata,
    ): void {
        $expected = $metadata['content_sha256'] ?? null;
        if (! is_string($expected) || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            if (config('connectors.case_study_email_dataset.require_fixture_index', true) === true) {
                throw new RuntimeException(
                    'Generated fixture metadata index has no valid content commitment.',
                );
            }

            return;
        }

        $separator = "\n---\n\n";
        $separatorAt = strpos($markdown, $separator);
        if ($separatorAt === false) {
            throw new RuntimeException(
                'Generated IMAP source does not match the committed email Markdown shape.',
            );
        }

        $body = substr($markdown, $separatorAt + strlen($separator));
        $actual = FixtureMetadataIndex::contentChecksum($title, $body);
        if (! hash_equals($expected, $actual)) {
            throw new RuntimeException(
                'Generated IMAP source content does not match its fixture metadata commitment.',
            );
        }
    }

    /**
     * A version-scoped case-study rollback intentionally soft-deletes the KB
     * projection while preserving its chunks and source file. If the exact
     * immutable fixture is delivered again, restore that projection before the
     * idempotent ingest job runs; otherwise the unique
     * (project_key, source_path, version_hash) tuple would remain occupied by a
     * hidden row.
     *
     * @param  array<string,mixed>  $metadata
     */
    private function restoreGeneratedFixtureIfRolledBack(
        string $projectKey,
        string $relativePath,
        string $tenantId,
        array $metadata,
    ): void {
        if (($metadata['generated_fixture'] ?? null) !== true) {
            return;
        }

        KnowledgeDocument::onlyTrashed()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where('source_path', $relativePath)
            ->where('metadata->generated_fixture', true)
            ->where('metadata->dataset_version', (string) $metadata['dataset_version'])
            ->where('metadata->fixture_id', (string) $metadata['fixture_id'])
            ->chunkById(100, static function ($documents): void {
                foreach ($documents as $document) {
                    if ($document->restore() !== true) {
                        throw new RuntimeException(
                            "Unable to restore rolled-back generated fixture document {$document->id}."
                        );
                    }
                }
            });
    }

    /**
     * Trust only the reserved fixture Message-ID namespace when marking
     * generated dataset mail. Connector-provided custom headers/metadata are
     * untrusted and cannot opt a document out of the normal AI pipelines.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withGeneratedFixtureMetadata(string $projectKey, array $metadata): array
    {
        unset(
            $metadata['generated_fixture'],
            $metadata['dataset_version'],
            $metadata['fixture_id'],
        );

        $messageId = $metadata['imap_message_id'] ?? null;

        if (! is_string($messageId)) {
            return $metadata;
        }

        $messageId = trim($messageId);

        if (str_starts_with($messageId, '<') || str_ends_with($messageId, '>')) {
            if (! str_starts_with($messageId, '<') || ! str_ends_with($messageId, '>')) {
                return $metadata;
            }

            $messageId = substr($messageId, 1, -1);
        }

        if (preg_match(
            '/\A([a-z0-9-]+)\.([a-f0-9]{64})@fixtures\.askmydocs\.invalid\z/D',
            $messageId,
            $matches,
        ) !== 1) {
            return $metadata;
        }

        $metadata['generated_fixture'] = true;
        $metadata['dataset_version'] = $matches[1];
        $metadata['fixture_id'] = $matches[2];

        return $this->withFixtureIndexMetadata($projectKey, $metadata);
    }

    /**
     * Recover the complete semantic metadata from the immutable, sharded
     * fixture index. The IMAP package intentionally exposes only standard mail
     * fields; this lookup keeps the transport payload small while still
     * preserving company/scenario/fact/truth provenance on the KB document.
     *
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function withFixtureIndexMetadata(string $projectKey, array $metadata): array
    {
        $root = trim((string) config(
            'connectors.case_study_email_dataset.root',
            storage_path('app/demo-email-datasets'),
        ));
        if ($root === '') {
            throw new RuntimeException('Case-study email dataset root cannot be empty.');
        }
        if (! str_starts_with($root, DIRECTORY_SEPARATOR)) {
            $root = base_path($root);
        }

        $required = config(
            'connectors.case_study_email_dataset.require_fixture_index',
            true,
        ) === true;
        $directory = $this->emailDatasetReader->datasetDirectory(
            $root,
            (string) $metadata['dataset_version'],
        );
        if (! is_dir($directory) && ! $required) {
            return $metadata;
        }

        $fixture = $this->emailDatasetReader->fixtureMetadataForVersion(
            $root,
            (string) $metadata['dataset_version'],
            (string) $metadata['fixture_id'],
        );
        if ($fixture === null) {
            if (! $required) {
                return $metadata;
            }

            throw new RuntimeException(
                "Generated fixture {$metadata['fixture_id']} is absent from dataset "
                ."{$metadata['dataset_version']} metadata index."
            );
        }

        if (($fixture['company_key'] ?? null) !== $projectKey) {
            throw new RuntimeException(
                "Generated fixture {$metadata['fixture_id']} belongs to "
                .(string) ($fixture['company_key'] ?? 'an unknown company')
                .", not project {$projectKey}."
            );
        }
        if (($fixture['mailbox_key'] ?? null) !== ($metadata['imap_mailbox'] ?? null)) {
            throw new RuntimeException(
                "Generated fixture {$metadata['fixture_id']} belongs to mailbox "
                .(string) ($fixture['mailbox_key'] ?? 'unknown')
                .', not '.(string) ($metadata['imap_mailbox'] ?? 'unknown').'.'
            );
        }

        unset($fixture['fixture_id']);

        return array_merge($metadata, $fixture);
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

        // The package RedactorEngine no-ops when its own engine flag is off, so
        // skip strategy resolution entirely in that case — otherwise a typo'd
        // KB_INGEST_PII_STRATEGY would throw even though no redaction would run.
        // The strict-strategy throw is thus reserved for when redaction is
        // actually active (engine ON), where the misconfig genuinely matters.
        if (! (bool) config('pii-redactor.enabled', false)) {
            return $content;
        }

        /** @var RedactorEngine $engine */
        $engine = app(RedactorEngine::class);

        return $engine->redact($content, $this->ingestStrategy());
    }

    /**
     * v8.23 (Ciclo 4) — the ingest redaction strategy. `tokenise` (reversible,
     * per-tenant vault) when configured, else `mask` (one-way, pre-v8.23
     * default). Delegated to the shared {@see IngestStrategyResolver} so the
     * mask-vs-tokenise mapping lives in ONE place (also used by the inline
     * `DocumentIngestor` path): `tokenise` is built through the package factory
     * (host-bound tenant resolver + salt) and an unknown value throws
     * immediately so an operator typo (e.g. `tokenize`) surfaces loudly at
     * ingest time rather than silently masking data (R14).
     *
     * @throws \InvalidArgumentException for unrecognised strategy values.
     */
    private function ingestStrategy(): \Padosoft\PiiRedactor\Strategies\RedactionStrategy
    {
        return app(IngestStrategyResolver::class)->forName(
            (string) config('kb.pii_redactor.ingest_strategy', 'mask'),
        );
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
