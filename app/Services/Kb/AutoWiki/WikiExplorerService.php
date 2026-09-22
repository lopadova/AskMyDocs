<?php

declare(strict_types=1);

namespace App\Services\Kb\AutoWiki;

use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * v8.11/P10 — the shared core (R44) behind the Wiki Explorer: browse the typed
 * wiki pages of a (tenant, project) filtered by provenance tier (auto / human),
 * each with its backlink + outgoing-edge counts, and the two editorial writes —
 * PROMOTE an auto page to the human-vouched tier, and DISCARD (soft-delete) an
 * auto page. Both writes are tenant-scoped (R30), audited to kb_canonical_audit,
 * and reversible; neither ever touches a human page (the ADR-0003 firewall).
 *
 * Promote flips `generation_source` auto→human so the reranker stops applying
 * the auto-tier penalty (the page becomes authoritative). Discard goes through
 * the single {@see DocumentDeleter} soft path (R10 #5 — never replicate delete).
 */
// Not final: mocked in WikiExplorerTriSurfaceTest (Mockery cannot replace final).
class WikiExplorerService
{
    public function __construct(
        private readonly DocumentDeleter $deleter,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * List wiki pages (canonical docs that carry a slug) for a tenant, optionally
     * scoped to one project and one provenance tier, newest first.
     *
     * @param  'all'|'auto'|'human'  $tier
     * @return array{tier: string, project_key: ?string, total: int, pages: list<array<string,mixed>>}
     */
    public function list(string $tenantId, ?string $projectKey = null, string $tier = 'all', int $limit = 100): array
    {
        $this->tenants->set($tenantId);
        $limit = max(1, min(500, $limit));

        $rows = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->whereNotNull('slug')
            ->when($projectKey !== null && $projectKey !== '', fn ($q) => $q->where('project_key', $projectKey))
            ->when($tier === GenerationSource::Auto->value, fn ($q) => $q->auto())
            ->when($tier === GenerationSource::Human->value, fn ($q) => $q->humanCurated())
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'project_key', 'slug', 'title', 'canonical_type', 'canonical_status', 'generation_source', 'updated_at']);

        [$outgoing, $backlinks] = $this->edgeCounts($tenantId, $rows->pluck('slug')->all());

        $pages = $rows->map(function (KnowledgeDocument $doc) use ($outgoing, $backlinks): array {
            $key = $doc->project_key.'|'.$doc->slug;

            return [
                'id' => (int) $doc->id,
                'project_key' => (string) $doc->project_key,
                'slug' => (string) $doc->slug,
                'title' => (string) ($doc->title ?? $doc->slug),
                'canonical_type' => $doc->canonical_type,
                'canonical_status' => $doc->canonical_status,
                'generation_source' => (string) ($doc->generation_source ?? GenerationSource::Human->value),
                'outgoing_edges' => (int) ($outgoing[$key] ?? 0),
                'backlinks' => (int) ($backlinks[$key] ?? 0),
                'updated_at' => optional($doc->updated_at)->toIso8601String(),
            ];
        })->all();

        return [
            'tier' => $tier,
            'project_key' => $projectKey,
            'total' => count($pages),
            'pages' => $pages,
        ];
    }

    /**
     * Promote an auto page to the human-vouched tier. Refuses (without error) on
     * a page that is already human, so a double-click is a safe no-op.
     *
     * Copilot PR #494 round 6 (must-fix) — this method unconditionally sets
     * `canonical_status = 'accepted'`, a canonical-only column. The
     * numeric-ID HTTP ({@see \App\Http\Controllers\Api\Admin\KbWikiExplorerController})
     * and CLI ({@see \App\Console\Commands\KbWikiPromoteCommand}) adapters
     * resolve a tenant-scoped {@see KnowledgeDocument} without requiring
     * `is_canonical` — so a non-canonical `auto` row (an OCR'd scan, or
     * AutoWiki-enriched raw content) reaching this method would receive a
     * FALSE canonical fact it was never meant to carry. (The MCP adapter,
     * {@see \App\Mcp\Tools\KbWikiPromoteTool}, is naturally safe: it
     * resolves by `doc_id`, which is always `null` on a non-canonical
     * document — `DocumentIngestor` never sets it there.) This is exactly
     * the case {@see \App\Services\Kb\Review\KbReviewService::approve()}
     * avoids in its own non-canonical branch, which flips
     * `generation_source` WITHOUT ever touching `canonical_status`.
     *
     * Copilot PR #494 round 9 (must-fix, 2 findings) — the eligibility
     * checks and `$before` audit snapshot used to run BEFORE any lock was
     * taken, against the `$doc` argument the caller happened to load. Two
     * races followed: (1) `KbReviewService::approve()`'s canonical branch
     * already `lockForUpdate()`s the row and calls this method from
     * INSIDE that lock — but the direct Wiki Explorer HTTP/CLI/MCP
     * adapters load the row with a plain `find()` and call this method
     * with no lock at all, so a concurrent promote-via-approve() and a
     * direct promote() could both pass the (stale) `auto` check before
     * either commits, racing to write two `promoted` audit rows; (2) a
     * concurrent re-ingest could flip the row non-canonical between the
     * caller's read and this method's write, and the write would still
     * stamp `canonical_status=accepted` on a now-non-canonical row. Moving
     * the `lockForUpdate()`, the eligibility re-check, and the `$before`
     * snapshot inside ONE transaction fixes both: every caller — locked
     * (approve(), whose lock this nests a savepoint under and re-reads for
     * free) or unlocked (the direct adapters) — now serializes on the
     * SAME row lock and decides from the row's state at the moment it
     * actually commits, never from a possibly-stale argument.
     *
     * @return array{promoted: bool, reason?: string, slug?: ?string}
     */
    public function promote(KnowledgeDocument $doc, string $actor): array
    {
        $tenantId = (string) $doc->tenant_id;
        $documentId = (int) $doc->id;

        return DB::transaction(function () use ($tenantId, $documentId, $actor): array {
            /** @var KnowledgeDocument $locked */
            $locked = KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->lockForUpdate()
                ->findOrFail($documentId);

            if ((string) ($locked->generation_source ?? GenerationSource::Human->value) !== GenerationSource::Auto->value) {
                return ['promoted' => false, 'reason' => 'not_auto', 'slug' => $locked->slug];
            }

            if (! (bool) $locked->is_canonical) {
                return ['promoted' => false, 'reason' => 'not_canonical', 'slug' => $locked->slug];
            }

            $before = ['generation_source' => (string) $locked->generation_source, 'canonical_status' => (string) ($locked->canonical_status ?? '')];

            // Copilot PR #494 round 5 — save() returns false when a model
            // event vetoes the write. Ignoring that let the transaction
            // fall through to writing the 'promoted' audit row (and this
            // method returning promoted=true) while generation_source/
            // canonical_status stayed untouched on disk. Throwing here
            // rolls back the whole transaction (row lock included), audit
            // row included, for every caller of promote() (the Wiki
            // Explorer HTTP/CLI/MCP surface and, via delegation,
            // KbReviewService::approve()'s canonical branch).
            if (! $locked->forceFill([
                'generation_source' => GenerationSource::Human->value,
                'canonical_status' => 'accepted',
            ])->save()) {
                throw new \RuntimeException("Failed to persist promotion for document {$documentId} (tenant {$tenantId}); a model event vetoed the save.");
            }

            if ((bool) config('kb.canonical.audit_enabled', true)) {
                KbCanonicalAudit::create([
                    'tenant_id' => $tenantId,
                    'project_key' => (string) $locked->project_key,
                    'doc_id' => $locked->doc_id,
                    'slug' => $locked->slug,
                    'event_type' => 'promoted',
                    'actor' => $actor,
                    'before_json' => $before,
                    'after_json' => ['generation_source' => GenerationSource::Human->value, 'canonical_status' => 'accepted'],
                    'metadata_json' => ['source' => 'wiki_explorer_promote'],
                ]);
            }

            return ['promoted' => true, 'slug' => $locked->slug];
        });
    }

    /**
     * Discard (soft-delete) an auto page. Refuses on a human page — the firewall
     * never lets the explorer remove human-vouched content. Soft delete keeps the
     * graph + the page recoverable via Time Machine until retention prunes it.
     *
     * @return array{discarded: bool, reason?: string, slug?: ?string}
     */
    public function discard(KnowledgeDocument $doc, string $actor): array
    {
        if ((string) ($doc->generation_source ?? GenerationSource::Human->value) !== GenerationSource::Auto->value) {
            return ['discarded' => false, 'reason' => 'not_auto', 'slug' => $doc->slug];
        }

        $slug = $doc->slug;
        $projectKey = (string) $doc->project_key;
        $docId = $doc->doc_id;
        $tenantId = (string) $doc->tenant_id;

        if ((bool) config('kb.canonical.audit_enabled', true)) {
            KbCanonicalAudit::create([
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'doc_id' => $docId,
                'slug' => $slug,
                'event_type' => 'deprecated',
                'actor' => $actor,
                'before_json' => ['generation_source' => GenerationSource::Auto->value],
                'after_json' => ['deleted' => true],
                'metadata_json' => ['source' => 'wiki_explorer_discard'],
            ]);
        }

        // The single soft-delete path (R10 #5) — never replicate it by hand.
        $this->deleter->delete($doc, force: false);

        return ['discarded' => true, 'slug' => $slug];
    }

    /**
     * Outgoing + backlink edge counts keyed by "project_key|node_uid" for the
     * given slugs. Two grouped queries, no N+1.
     *
     * @param  list<string>  $slugs
     * @return array{0: array<string,int>, 1: array<string,int>}
     */
    private function edgeCounts(string $tenantId, array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter($slugs, static fn ($s): bool => is_string($s) && $s !== '')));
        if ($slugs === []) {
            return [[], []];
        }

        $out = [];
        foreach (array_chunk($slugs, 1000) as $chunk) {
            KbEdge::query()->forTenant($tenantId)
                ->whereIn('from_node_uid', $chunk)
                ->get(['project_key', 'from_node_uid'])
                ->each(function (KbEdge $e) use (&$out): void {
                    $key = $e->project_key.'|'.$e->from_node_uid;
                    $out[$key] = ($out[$key] ?? 0) + 1;
                });
        }

        $in = [];
        foreach (array_chunk($slugs, 1000) as $chunk) {
            KbEdge::query()->forTenant($tenantId)
                ->whereIn('to_node_uid', $chunk)
                ->get(['project_key', 'to_node_uid'])
                ->each(function (KbEdge $e) use (&$in): void {
                    $key = $e->project_key.'|'.$e->to_node_uid;
                    $in[$key] = ($in[$key] ?? 0) + 1;
                });
        }

        return [$out, $in];
    }
}
