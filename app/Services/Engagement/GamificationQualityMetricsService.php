<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Models\KbCanonicalHealthSnapshot;
use App\Models\KbContributionEvent;
use App\Models\KbEdge;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * v8.18/W4 — CURATION-QUALITY metrics for the AI gamification layer.
 *
 * Where {@see GamificationService} measures QUANTITY (score / events / authored /
 * active_days), this service measures how WELL a contributor / project / tenant
 * curates knowledge, derived entirely from data that already exists:
 * frontmatter completeness, evidence rigor, canonicalization success, graph
 * connectivity, stewardship/freshness (health snapshots), and structural depth.
 *
 * All reads are tenant-scoped (R30) and SQL-aggregated (R3); the service holds
 * NO LLM logic — {@see GamificationNarratorService} turns these numbers into
 * narratives. Pure reads, no writes.
 */
final class GamificationQualityMetricsService
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    /**
     * Curation-quality metrics for a single contributor: the quality of the docs
     * they authored + their stewardship footprint.
     *
     * @return array<string, mixed>
     */
    public function userQuality(int $userId): array
    {
        $tenant = $this->tenants->current();

        // R3: NEVER materialise the id list — `$scopedDocs()` is a fresh
        // tenant-scoped Builder of the user's authored docs (a subquery), so every
        // aggregate below runs entirely in SQL with no PHP-side id arrays.
        $scopedDocs = fn () => KnowledgeDocument::query()
            ->forTenant($tenant)
            ->whereIn('id', $this->authoredDocIdSubquery($tenant, $userId));

        $quality = $this->docSetQuality($scopedDocs);

        $citations = (int) KbContributionEvent::query()
            ->forTenant($tenant)
            ->where('event', KbContributionEvent::EVENT_CITED)
            ->whereIn('document_id', $this->authoredDocIdSubquery($tenant, $userId))
            ->count();

        return [
            'authored_docs' => (int) $scopedDocs()->count(),
            'citation_usefulness' => $citations,
        ] + $quality;
    }

    /**
     * Composite knowledge-health metrics for one project_key.
     *
     * @return array<string, mixed>
     */
    public function projectQuality(string $projectKey): array
    {
        $tenant = $this->tenants->current();

        $scopedDocs = fn () => KnowledgeDocument::query()
            ->forTenant($tenant)
            ->where('project_key', $projectKey);

        $quality = $this->docSetQuality($scopedDocs);

        $contributors = (int) KbContributionEvent::query()
            ->forTenant($tenant)
            ->where('project_key', $projectKey)
            ->whereNotNull('user_id')
            ->distinct()
            ->count('user_id');

        return [
            'project_key' => $projectKey,
            'total_docs' => $quality['total_docs'],
            'contributors' => $contributors,
            'health_score' => $this->compositeHealthScore($quality),
        ] + $quality;
    }

    /**
     * Tenant-wide rollup: org health + a per-project comparison + a cross-dev
     * strength matrix (who is the steward / cartographer / evidence champion).
     *
     * @return array<string, mixed>
     */
    public function tenantQuality(): array
    {
        $tenant = $this->tenants->current();

        // The distinct project-key list is intrinsically small (one row per
        // project, not per document) — safe to pluck.
        $projectKeys = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->whereNotNull('project_key')
            ->distinct()
            ->orderBy('project_key')
            ->pluck('project_key')
            ->all();

        $projects = [];
        foreach ($projectKeys as $key) {
            $p = $this->projectQuality((string) $key);
            $projects[] = [
                'project_key' => $p['project_key'],
                'total_docs' => $p['total_docs'],
                'health_score' => $p['health_score'],
                'canonicalization_rate' => $p['canonicalization_rate'],
                'frontmatter_completeness_rate' => $p['frontmatter_completeness_rate'],
                'contributors' => $p['contributors'],
            ];
        }

        $org = $this->docSetQuality(fn () => KnowledgeDocument::query()->forTenant($tenant));

        return [
            'projects' => $projects,
            'project_count' => count($projects),
            'total_docs' => $org['total_docs'],
            'org_health_score' => $this->compositeHealthScore($org),
            'strength_matrix' => $this->strengthMatrix(),
        ] + $org;
    }

    /**
     * Quality aggregate over a doc set described by a Builder FACTORY (`$scopedDocs`
     * returns a fresh tenant-scoped `KnowledgeDocument` query each call). Every
     * aggregate runs in SQL — including the health / edges / chunk counts, which
     * pass `$scopedDocs()` as a SUBQUERY rather than a PHP id array (R3: no
     * unbounded id materialisation, no oversized `IN (…)`). Returns zero-valued,
     * well-formed metrics for an empty set so an inactive user/project never
     * crashes the narrator or the dashboard.
     *
     * @param  \Closure(): \Illuminate\Database\Eloquent\Builder<KnowledgeDocument>  $scopedDocs
     * @return array<string, mixed>
     */
    private function docSetQuality(\Closure $scopedDocs): array
    {
        $tenant = $this->tenants->current();

        $total = (int) $scopedDocs()->count();
        if ($total === 0) {
            return $this->emptyQuality();
        }

        $canonical = (int) $scopedDocs()->canonical()->count();
        $accepted = (int) $scopedDocs()->accepted()->count();
        // `frontmatter_json` is a JSON column — `whereNotNull` is the only
        // cross-driver-safe "is present" test (string comparisons like `!= ''`
        // throw on Postgres, which has no `json <> text` operator).
        $withFrontmatter = (int) $scopedDocs()->canonical()->whereNotNull('frontmatter_json')->count();
        // evidence_tier is a plain string column, so `!= ''` is fine here.
        $withEvidence = (int) $scopedDocs()
            ->whereNotNull('evidence_tier')
            ->where('evidence_tier', '!=', '')
            ->count();
        $avgPriority = (float) ($scopedDocs()->avg('retrieval_priority') ?? 0.0);

        // Stewardship: avg canonical-health-snapshot score over these docs (subquery).
        $avgHealth = (float) (KbCanonicalHealthSnapshot::query()
            ->forTenant($tenant)
            ->whereIn('knowledge_document_id', $scopedDocs()->select('id'))
            ->avg('health_score') ?? 0.0);

        // Graph connectivity: edges sourced from these docs by canonical doc_id (subquery).
        $edges = (int) KbEdge::query()
            ->forTenant($tenant)
            ->whereIn('source_doc_id', $scopedDocs()->canonical()->whereNotNull('doc_id')->select('doc_id'))
            ->count();

        // Structural depth: avg chunks per doc (subquery).
        $chunks = (int) KnowledgeChunk::query()
            ->forTenant($tenant)
            ->whereIn('knowledge_document_id', $scopedDocs()->select('id'))
            ->count();

        return [
            'total_docs' => $total,
            'canonical_docs' => $canonical,
            'accepted_docs' => $accepted,
            'canonicalization_rate' => $this->ratio($accepted, max(1, $canonical)),
            'frontmatter_completeness_rate' => $this->ratio($withFrontmatter, max(1, $canonical)),
            'evidence_coverage_rate' => $this->ratio($withEvidence, max(1, $total)),
            'avg_retrieval_priority' => round($avgPriority, 2),
            'avg_health_score' => round($avgHealth, 2),
            'graph_edges' => $edges,
            'avg_chunks_per_doc' => round($chunks / max(1, $total), 2),
            'evidence_tier_breakdown' => $this->evidenceBreakdown($scopedDocs),
        ];
    }

    /**
     * Distinct document_ids a user authored (created|promoted) — a fresh subquery
     * builder each call (R30: explicit tenant filter; R3: stays a SQL subquery).
     */
    private function authoredDocIdSubquery(string $tenant, int $userId): \Illuminate\Database\Query\Builder
    {
        return DB::table('kb_contribution_events')
            ->select('document_id')
            ->where('tenant_id', $tenant)
            ->where('user_id', $userId)
            ->whereIn('event', [KbContributionEvent::EVENT_CREATED, KbContributionEvent::EVENT_PROMOTED])
            ->whereNotNull('document_id')
            ->distinct();
    }

    /**
     * Cross-dev strength matrix: the top contributors and their dominant axis.
     *
     * @return list<array<string, mixed>>
     */
    private function strengthMatrix(): array
    {
        $tenant = $this->tenants->current();

        $userIds = KbContributionEvent::query()
            ->forTenant($tenant)
            ->whereNotNull('user_id')
            ->select('user_id')
            ->groupBy('user_id')
            ->orderByRaw('SUM(weight) DESC')
            ->limit(8)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $matrix = [];
        foreach ($userIds as $userId) {
            $q = $this->userQuality($userId);
            $matrix[] = [
                'user_id' => $userId,
                'authored_docs' => $q['authored_docs'],
                'canonicalization_rate' => $q['canonicalization_rate'],
                'frontmatter_completeness_rate' => $q['frontmatter_completeness_rate'],
                'graph_edges' => $q['graph_edges'],
                'avg_health_score' => $q['avg_health_score'],
                'dominant_axis' => $this->dominantAxis($q),
            ];
        }

        return $matrix;
    }

    /**
     * Pick the contributor's standout quality axis (a fun persona seed for the AI).
     *
     * @param  array<string, mixed>  $q
     */
    private function dominantAxis(array $q): string
    {
        $candidates = [
            'cartographer' => (float) ($q['graph_edges'] ?? 0) / 10.0,
            'evidence-champion' => (float) ($q['evidence_coverage_rate'] ?? 0.0),
            'steward' => (float) ($q['avg_health_score'] ?? 0.0),
            'archivist' => (float) ($q['frontmatter_completeness_rate'] ?? 0.0),
            'canonizer' => (float) ($q['canonicalization_rate'] ?? 0.0),
        ];
        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    /**
     * A single 0–100 composite from the quality axes (transparent weighted mean).
     *
     * @param  array<string, mixed>  $q
     */
    private function compositeHealthScore(array $q): float
    {
        $canonicalization = (float) ($q['canonicalization_rate'] ?? 0.0);
        $frontmatter = (float) ($q['frontmatter_completeness_rate'] ?? 0.0);
        $evidence = (float) ($q['evidence_coverage_rate'] ?? 0.0);
        $health = (float) ($q['avg_health_score'] ?? 0.0); // already 0..1-ish or 0..100? normalise below
        $healthNorm = $health > 1.0 ? min(1.0, $health / 100.0) : $health;

        $score = 100.0 * (
            0.30 * $canonicalization
            + 0.25 * $frontmatter
            + 0.20 * $evidence
            + 0.25 * $healthNorm
        );

        return round($score, 1);
    }

    /**
     * @param  \Closure(): \Illuminate\Database\Eloquent\Builder<KnowledgeDocument>  $scopedDocs
     * @return array<string, int>
     */
    private function evidenceBreakdown(\Closure $scopedDocs): array
    {
        $rows = $scopedDocs()
            ->whereNotNull('evidence_tier')
            ->where('evidence_tier', '!=', '')
            ->select('evidence_tier', DB::raw('COUNT(*) as c'))
            ->groupBy('evidence_tier')
            ->pluck('c', 'evidence_tier')
            ->all();

        $out = [];
        foreach ($rows as $tier => $count) {
            $out[(string) $tier] = (int) $count;
        }

        return $out;
    }

    private function ratio(int $num, int $den): float
    {
        return $den <= 0 ? 0.0 : round($num / $den, 4);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyQuality(): array
    {
        return [
            'total_docs' => 0,
            'canonical_docs' => 0,
            'accepted_docs' => 0,
            'canonicalization_rate' => 0.0,
            'frontmatter_completeness_rate' => 0.0,
            'evidence_coverage_rate' => 0.0,
            'avg_retrieval_priority' => 0.0,
            'avg_health_score' => 0.0,
            'graph_edges' => 0,
            'avg_chunks_per_doc' => 0.0,
            'evidence_tier_breakdown' => [],
        ];
    }
}
