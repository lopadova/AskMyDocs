<?php

declare(strict_types=1);

namespace App\Services\Kb\Pii;

use App\Jobs\ReembedDocumentJob;
use App\Models\KnowledgeDocument;
use App\Scopes\AccessScopeScope;

/**
 * v8.23 (Ciclo 4, PR5) — fan out a per-(tenant, project) re-embed after a PII
 * policy change.
 *
 * The ONE core behind the tri-surface (HTTP + CLI + MCP) re-embed capability
 * (R44): it queues one {@see ReembedDocumentJob} per live document in the
 * project so each is re-derived from disk under the CURRENT policy. Tenant-scoped
 * (R30); reads only the `id` and streams via `chunkById` (R3) so a large project
 * never loads every row into memory.
 */
final class ReembedProjectService
{
    /**
     * Queue a re-embed for every live document in the (tenant, project).
     * Returns the number of documents queued.
     */
    public function reembedProject(string $tenantId, string $projectKey): int
    {
        $queued = 0;

        KnowledgeDocument::query()
            ->withoutGlobalScope(AccessScopeScope::class)
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where('status', 'active')
            ->select('id')
            ->chunkById(100, function ($documents) use (&$queued, $tenantId): void {
                foreach ($documents as $document) {
                    ReembedDocumentJob::dispatch((int) $document->id, $tenantId);
                    $queued++;
                }
            });

        return $queued;
    }
}
