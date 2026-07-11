<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use App\Models\KnowledgeDocument;
use App\Support\TenantContext;

/**
 * v8.31 — per-installation "at a glance" stats for the Edit → Details tab of the
 * connector modal (the redesigned "Config Modals" surface): how many KB documents
 * this account has synced, and when it last synced.
 *
 * `documents_synced` counts LIVE (non-soft-deleted) `knowledge_documents` whose
 * `metadata->installation_id` matches — every connector stamps `installation_id`
 * as a top-level metadata key at ingest (base connector's
 * `SourceAwareMetadataBuilder`; IMAP via `MailMetadata::build`), so the count is
 * connector-agnostic. The `metadata->installation_id` JSON `where` is the same
 * portable form the ingest bridge already uses for remote-id lookups (SQLite in
 * tests, Postgres in prod).
 *
 * Read-only + lazily fetched (only when the Details tab opens), so the connectors
 * LIST endpoint stays free of an N-per-installation count — a metadata-path count
 * is unindexed and would be a per-row full scan on a hot endpoint. R30 — scoped to
 * the active tenant, and the installation itself is resolved through
 * {@see ConnectorInstallationService::findOr404} so a cross-tenant / unknown id
 * 404s rather than leaking another tenant's count.
 */
final class ConnectorInstallationStatsService
{
    public function __construct(
        private readonly ConnectorInstallationService $installations,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{documents_synced: int, last_sync_at: string|null}
     */
    public function forInstallation(int $installationId): array
    {
        // Tenant-scoped resolve (404 on cross-tenant / unknown) BEFORE any count,
        // so the count query never runs for an id the caller can't see.
        $installation = $this->installations->findOr404($installationId);

        $documentsSynced = KnowledgeDocument::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('metadata->installation_id', $installationId)
            ->count();

        return [
            'documents_synced' => $documentsSynced,
            'last_sync_at' => $installation->last_sync_at?->toIso8601String(),
        ];
    }
}
