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

        $authoredIds = KbContributionEvent::query()
            ->forTenant($tenant)
            ->where('user_id', $userId)
            ->whereIn('event', [KbContributionEvent::EVENT_CREATED, KbContributionEvent::EVENT_PROMOTED])
            ->whereNotNull('document_id')
            ->distinct()
            ->pluck('document_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $quality = $this->docSetQuality($authoredIds);

        $citations = (int) KbContributionEvent::query()
            ->forTenant($tenant)
            ->where('event', KbContributionEvent::EVENT_CITED)
            ->when($authoredIds !== [], fn ($q) => $q->whereIn('document_id', $authoredIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->count();

        return [
            'authored_docs' => count($authoredIds),
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

        $docIds = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->where('project_key', $projectKey)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $quality = $this->docSetQuality($docIds);

        $contributors = (int) KbContributionEvent::query()
            ->forTenant($tenant)
            ->where('project_key', $projectKey)
            ->whereNotNull('user_id')
            ->distinct()
            ->count('user_id');

        return [
            'project_key' => $projectKey,
            'total_docs' => count($docIds),
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

        $allDocIds = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $org = $this->docSetQuality($allDocIds);

        return [
            'projects' => $projects,
            'project_count' => count($projects),
            'total_docs' => count($allDocIds),
            'org_health_score' => $this->compositeHealthScore($org),
            'strength_matrix' => $this->strengthMatrix(),
        ] + $org;
    }

    /**
     * Quality aggregate over a set of knowledge_documents primary keys. Returns
     * zero-valued, well-formed metrics for an empty set (so an inactive
     * user/project never crashes the narrator or the dashboard).
     *
     * @param  list<int>  $docIds
     * @return array<string, mixed>
     */
    private function docSetQuality(array $docIds): array
    {
        if ($docIds === []) {
            return $this->emptyQuality();
        }

        $tenant = $this->tenants->current();
        $base = KnowledgeDocument::query()->forTenant($tenant)->whereIn('id', $docIds);

        $total = (int) (clone $base)->count();
        $canonical = (int) (clone $base)->canonical()->count();
        $accepted = (int) (clone $base)->accepted()->count();
        $withFrontmatter = (int) (clone $base)
            ->canonical()
            ->whereNotNull('frontmatter_json')
            ->where('frontmatter_json', '!=', '')
            ->where('frontmatter_json', '!=', '[]')
            ->count();
        $withEvidence = (int) (clone $base)
            ->whereNotNull('evidence_tier')
            ->where('evidence_tier', '!=', '')
            ->count();
        $avgPriority = (float) ((clone $base)->avg('retrieval_priority') ?? 0.0);

        // Stewardship: avg canonical-health-snapshot score over these docs.
        $avgHealth = (float) (KbCanonicalHealthSnapshot::query()
            ->forTenant($tenant)
            ->whereIn('knowledge_document_id', $docIds)
            ->avg('health_score') ?? 0.0);

        // Graph connectivity: edges sourced from these docs (by canonical doc_id).
        $docIdSlugs = (clone $base)->canonical()->whereNotNull('doc_id')->pluck('doc_id')->all();
        $edges = $docIdSlugs === [] ? 0 : (int) KbEdge::query()
            ->forTenant($tenant)
            ->whereIn('source_doc_id', $docIdSlugs)
            ->count();

        // Structural depth: avg chunks per doc.
        $chunks = (int) KnowledgeChunk::query()
            ->forTenant($tenant)
            ->whereIn('knowledge_document_id', $docIds)
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
            'evidence_tier_breakdown' => $this->evidenceBreakdown($docIds),
        ];
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
     * @param  list<int>  $docIds
     * @return array<string, int>
     */
    private function evidenceBreakdown(array $docIds): array
    {
        if ($docIds === []) {
            return [];
        }

        $rows = KnowledgeDocument::query()
            ->forTenant($this->tenants->current())
            ->whereIn('id', $docIds)
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
