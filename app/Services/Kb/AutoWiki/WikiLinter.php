<?php

declare(strict_types=1);

namespace App\Services\Kb\AutoWiki;

use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KbWikiIndex;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * v8.11/P5 — Auto-Wiki lint / wiki health (Karpathy "lint").
 *
 * Deterministic structural checks over the graph + docs of one (tenant,project):
 *   - dangling      : kb_nodes flagged dangling (wikilinked target with no
 *                     owning doc yet);
 *   - orphan        : real (non-dangling) nodes with NO incoming AND NO outgoing
 *                     edges — pages nothing links to and that link nowhere;
 *   - stale_cross_ref: edges whose target doc is deprecated / superseded /
 *                     archived / soft-deleted — a link the reader shouldn't
 *                     follow;
 *   - missing_index : the project has pages but no kb_wiki_indices row (P4).
 *
 * Semantic contradiction detection (LLM comparison of near-duplicate pairs) is
 * intentionally deferred to P7 (cross-model review / novelty), which is the
 * LLM-comparison phase — this linter stays deterministic, fast, and free.
 *
 * `fix()` applies only SAFE, reversible-by-rebuild fixes (prune leftover
 * dangling nodes that nothing references anymore); everything else is reported
 * for human/AI follow-up. Tenant-scoped (R30); audited.
 *
 * Tri-surface (R44): `kb:wiki-lint`, the admin HTTP endpoints, and the
 * `KbWikiLintTool` MCP tool — all over this ONE core.
 */
class WikiLinter
{
    private const STALE_STATUSES = ['deprecated', 'superseded', 'archived'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Run the lint checks for a project. Read-only.
     *
     * @return array{project_key: string, findings: array{dangling: list<string>, orphan: list<string>, stale_cross_ref: list<array{edge: string, target: string, reason: string}>, missing_index: bool}, counts: array<string,int>, healthy: bool}
     */
    public function lint(string $tenantId, string $projectKey): array
    {
        $this->tenants->set($tenantId);

        $dangling = $this->danglingNodes($tenantId, $projectKey);
        $orphan = $this->orphanNodes($tenantId, $projectKey);
        $stale = $this->staleCrossRefs($tenantId, $projectKey);
        $missingIndex = ! KbWikiIndex::query()->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where('index_type', KbWikiIndex::TYPE_PROJECT)
            ->exists();

        $counts = [
            'dangling' => count($dangling),
            'orphan' => count($orphan),
            'stale_cross_ref' => count($stale),
            'missing_index' => $missingIndex ? 1 : 0,
        ];

        return [
            'project_key' => $projectKey,
            'findings' => [
                'dangling' => $dangling,
                'orphan' => $orphan,
                'stale_cross_ref' => $stale,
                'missing_index' => $missingIndex,
            ],
            'counts' => $counts,
            'healthy' => array_sum($counts) === 0,
        ];
    }

    /**
     * Apply safe auto-fixes: prune leftover dangling nodes that no edge points
     * at anymore (a referenced target was removed). Audited. Returns what changed.
     *
     * @return array{pruned_dangling: int, pruned: list<string>}
     */
    public function fix(string $tenantId, string $projectKey): array
    {
        $this->tenants->set($tenantId);

        $referenced = $this->referencedToUids($tenantId, $projectKey);
        $pruned = [];

        // INVARIANT: dangling nodes are only ever created as edge TARGETS
        // (ensureTargetNode / PopulateCanonicalNodesStep / AutoWikiGraphLinker),
        // never as edge sources. So guarding on the referenced-TO set is
        // sufficient: a pruned dangling node has no incoming edge, and (by the
        // invariant) no outgoing edge either, so the FK ON DELETE CASCADE has
        // nothing to take with it. If a future path makes a dangling node an
        // edge SOURCE, extend this guard to the from_node_uid set too.
        DB::transaction(function () use ($tenantId, $projectKey, $referenced, &$pruned): void {
            foreach ($this->danglingNodes($tenantId, $projectKey) as $uid) {
                if (in_array($uid, $referenced, true)) {
                    continue; // still referenced — keep (it's an intended-but-missing page)
                }
                KbNode::query()->forTenant($tenantId)
                    ->where('project_key', $projectKey)
                    ->where('node_uid', $uid)
                    ->delete();
                $pruned[] = $uid;
            }
        });

        if ($pruned !== [] && (bool) config('kb.canonical.audit_enabled', true)) {
            KbCanonicalAudit::create([
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'event_type' => 'graph_rebuild',
                'actor' => 'system:autowiki',
                'after_json' => ['pruned_dangling' => $pruned],
                'metadata_json' => ['source' => 'wiki_lint_fix'],
            ]);
        }

        return ['pruned_dangling' => count($pruned), 'pruned' => $pruned];
    }

    /** @return list<string> node_uids flagged dangling */
    private function danglingNodes(string $tenantId, string $projectKey): array
    {
        return KbNode::query()->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where('payload_json->dangling', true)
            ->orderBy('node_uid')
            ->pluck('node_uid')
            ->all();
    }

    /** @return list<string> node_uids that are real (non-dangling) and have no edges */
    private function orphanNodes(string $tenantId, string $projectKey): array
    {
        $referencedTo = $this->referencedToUids($tenantId, $projectKey);
        $referencedFrom = KbEdge::query()->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->distinct()
            ->pluck('from_node_uid')
            ->all();
        $referenced = array_flip(array_merge($referencedTo, $referencedFrom));

        $orphans = [];
        KbNode::query()->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where(function ($q): void {
                $q->where('payload_json->dangling', '!=', true)
                    ->orWhereNull('payload_json');
            })
            ->select(['id', 'node_uid'])
            ->chunkById(500, function ($nodes) use (&$orphans, $referenced): void {
                foreach ($nodes as $node) {
                    if (! isset($referenced[$node->node_uid])) {
                        $orphans[] = (string) $node->node_uid;
                    }
                }
            });
        sort($orphans);

        return $orphans;
    }

    /** @return list<array{edge: string, target: string, reason: string}> */
    private function staleCrossRefs(string $tenantId, string $projectKey): array
    {
        $targets = $this->referencedToUids($tenantId, $projectKey);
        if ($targets === []) {
            return [];
        }

        // Map target slug → stale reason (deprecated/superseded/archived/deleted).
        $reasonBySlug = [];
        foreach (array_chunk($targets, 1000) as $chunk) {
            KnowledgeDocument::query()
                ->withTrashed()
                ->forTenant($tenantId)
                ->where('project_key', $projectKey)
                ->whereIn('slug', $chunk)
                ->get(['slug', 'canonical_status', 'deleted_at'])
                ->each(function ($doc) use (&$reasonBySlug): void {
                    if ($doc->deleted_at !== null) {
                        $reasonBySlug[(string) $doc->slug] = 'deleted';
                    } elseif (in_array((string) $doc->canonical_status, self::STALE_STATUSES, true)) {
                        $reasonBySlug[(string) $doc->slug] = (string) $doc->canonical_status;
                    }
                });
        }
        if ($reasonBySlug === []) {
            return [];
        }

        $stale = [];
        // Split the stale-target IN list into <=1000-value chunks (R3) so the
        // generated SQL stays parser-friendly even on a project with many
        // deprecated/deleted link targets.
        foreach (array_chunk(array_keys($reasonBySlug), 1000) as $slugChunk) {
            KbEdge::query()->forTenant($tenantId)
                ->where('project_key', $projectKey)
                ->whereIn('to_node_uid', $slugChunk)
                ->select(['id', 'edge_uid', 'to_node_uid'])
                ->chunkById(500, function ($edges) use (&$stale, $reasonBySlug): void {
                    foreach ($edges as $edge) {
                        $stale[] = [
                            'edge' => (string) $edge->edge_uid,
                            'target' => (string) $edge->to_node_uid,
                            'reason' => $reasonBySlug[(string) $edge->to_node_uid] ?? 'unknown',
                        ];
                    }
                }, 'id');
        }

        return $stale;
    }

    /** @return list<string> distinct to_node_uid referenced by edges in the project */
    private function referencedToUids(string $tenantId, string $projectKey): array
    {
        return KbEdge::query()->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->distinct()
            ->pluck('to_node_uid')
            ->all();
    }
}
