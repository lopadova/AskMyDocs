<?php

declare(strict_types=1);

namespace App\Services\Kb\Access;

use App\Models\UnmappedSourcePrincipal;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The queue of principals a source named and this application could not place
 * (ADR 0028 phase 2).
 *
 * One core behind three surfaces (R44): `kb:source-acl`, the admin API, and
 * the MCP read tool all come through here, so tenant scoping and the meaning
 * of "outstanding" are defined once.
 *
 * Nothing in this class grants access. Resolving an entry means an operator
 * creating an ordinary ACL row, which is a separate and deliberate act — the
 * queue only makes the question visible.
 */
final class SourceAclTriageService
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Counts an operator can act on, for the current tenant.
     *
     * `documents_restricted` is the one number that is easy to misread, so it
     * is worth being explicit: it counts documents whose readers the SOURCE
     * stated, which are therefore no longer visible to a project at large.
     * A high number is not a problem — it is the feature working.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $tenantId = $this->tenant->current();

        $base = UnmappedSourcePrincipal::query()->forTenant($tenantId);

        return [
            'pending' => (clone $base)->pending()->count(),
            'ignored' => (clone $base)
                ->where('status', UnmappedSourcePrincipal::STATUS_IGNORED)
                ->count(),
            'documents_affected' => (clone $base)
                ->distinct()
                ->count('knowledge_document_id'),
            'documents_restricted' => $this->restrictedDocumentCount($tenantId),
        ];
    }

    /**
     * The queue itself, newest question first.
     *
     * @return Collection<int, UnmappedSourcePrincipal>
     */
    public function queue(
        ?string $projectKey = null,
        string $status = UnmappedSourcePrincipal::STATUS_PENDING,
        int $limit = 50,
    ): Collection {
        $query = UnmappedSourcePrincipal::query()
            ->forTenant($this->tenant->current())
            ->with('document:id,title,source_path,project_key')
            ->where('status', $status);

        if ($projectKey !== null && $projectKey !== '') {
            $query->where('project_key', $projectKey);
        }

        return $query
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 200)))
            ->get();
    }

    /**
     * Record a decision about one entry.
     *
     * Returns null when the entry does not belong to the active tenant, so
     * every caller answers 404 rather than leaking that the id exists (R30).
     */
    public function setStatus(int $id, string $status): ?UnmappedSourcePrincipal
    {
        if (! in_array($status, UnmappedSourcePrincipal::STATUSES, true)) {
            throw new \InvalidArgumentException(
                'Unknown triage status: '.$status
            );
        }

        $row = UnmappedSourcePrincipal::query()
            ->forTenant($this->tenant->current())
            ->find($id);

        if ($row === null) {
            return null;
        }

        $row->status = $status;
        $row->save();

        return $row;
    }

    /**
     * How many documents currently have their readers dictated by a source.
     *
     * Read from the same column the global scope enforces on, and not counted
     * from mirrored ACL rows. A source that names only people this
     * application cannot place produces a restricted document with zero rows,
     * and counting rows would report those -- the ones most worth an
     * operator's attention -- as unrestricted.
     *
     * Deliberately a raw query rather than Eloquent: KnowledgeDocument
     * carries AccessScopeScope, and this is an operational count of what the
     * tenant holds, not a read of documents the current user may see.
     */
    private function restrictedDocumentCount(string $tenantId): int
    {
        return DB::table('knowledge_documents')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('source_acl_enforced_at')
            ->count();
    }
}
