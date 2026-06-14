<?php

declare(strict_types=1);

namespace App\Services\Kb\AutoWiki;

use App\Models\KbCanonicalAudit;
use App\Models\KbWikiIndex;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * v8.11/P4 — Auto-Wiki indices (Karpathy `index.md` hub + AutoSci anchor map)
 * and the operation log.
 *
 * Builds two kinds of index artifact into {@see KbWikiIndex} (deterministic — no
 * LLM, no disk write): a per-(tenant,project) roll-up and a per-tenant hub (the
 * map the agentic retrieval anchors on). The artifacts are a pure projection of
 * the corpus, so they can be rebuilt at any time.
 *
 * The index is materialised as STRUCTURED ROWS rather than a separate ingested
 * `project-index` markdown doc: the canonical disk path doesn't carry
 * `project_key`, so writing one `project-index.md` per project would collide on
 * disk. The rows carry the same TOC data (page list + counts) and a rendered
 * markdown view, consumed by the read surfaces and (P6) the wiki navigator.
 *
 * The operation log is a read over the immutable, append-only
 * {@see KbCanonicalAudit} filtered to the auto-wiki actor — no separate log
 * table (audit already IS the append-only log).
 *
 * Tri-surface (R44): `kb:wiki-index`, the admin HTTP endpoints, and the
 * `KbBuildWikiIndexTool` / `KbWikiHubTool` MCP tools. Tenant-scoped (R30).
 */
class WikiIndexBuilder
{
    private const AUTO_ACTOR = 'system:autowiki';
    private const RECENT_LIMIT = 20;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Rebuild the index for one project (when given) or every project in the
     * tenant, then refresh the tenant hub. Returns a summary.
     *
     * @return array{projects: list<string>, hub_project_count: int}
     */
    public function rebuild(string $tenantId, ?string $projectKey = null): array
    {
        $this->tenants->set($tenantId);

        $projects = $projectKey !== null && $projectKey !== ''
            ? [$projectKey]
            : $this->projectKeys($tenantId);

        foreach ($projects as $project) {
            $this->buildProjectIndex($tenantId, $project);
        }

        $hub = $this->buildTenantHub($tenantId);

        return ['projects' => $projects, 'hub_project_count' => (int) ($hub['project_count'] ?? 0)];
    }

    /**
     * Build/refresh the per-(tenant,project) roll-up row. Returns the payload.
     *
     * @return array<string,mixed>
     */
    public function buildProjectIndex(string $tenantId, string $projectKey): array
    {
        $countsByType = [];
        $conceptCount = 0;
        $autoCount = 0;
        $humanCount = 0;

        // Full-corpus aggregation: memory-safe chunkById (orders by id; the
        // chunk column MUST be in the select).
        KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->whereNotNull('slug')
            ->select(['id', 'canonical_type', 'generation_source'])
            ->chunkById(200, function ($docs) use (&$countsByType, &$conceptCount, &$autoCount, &$humanCount): void {
                foreach ($docs as $doc) {
                    $type = (string) ($doc->canonical_type ?? 'unknown');
                    $countsByType[$type] = ($countsByType[$type] ?? 0) + 1;
                    if ($type === 'domain-concept') {
                        $conceptCount++;
                    }
                    ((string) $doc->generation_source === 'auto') ? $autoCount++ : $humanCount++;
                }
            });

        // Recently-changed is a separate bounded query (chunkById can't order
        // by updated_at — it paginates by id).
        $pages = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->whereNotNull('slug')
            ->orderByDesc('updated_at')
            ->limit(self::RECENT_LIMIT)
            ->get(['slug', 'title', 'canonical_type', 'generation_source'])
            ->map(fn ($doc): array => [
                'slug' => (string) $doc->slug,
                'title' => (string) ($doc->title ?? $doc->slug),
                'type' => (string) ($doc->canonical_type ?? 'unknown'),
                'generation_source' => (string) $doc->generation_source,
            ])
            ->all();

        ksort($countsByType);
        $payload = [
            'page_counts_by_type' => $countsByType,
            'page_total' => array_sum($countsByType),
            'concept_count' => $conceptCount,
            'auto_count' => $autoCount,
            'human_count' => $humanCount,
            'recently_changed' => $pages,
            'rendered_markdown' => $this->renderProjectMarkdown($projectKey, $countsByType, $pages),
            'built_at' => Carbon::now()->toIso8601String(),
        ];

        KbWikiIndex::query()->forTenant($tenantId)->updateOrCreate(
            ['tenant_id' => $tenantId, 'project_key' => $projectKey, 'index_type' => KbWikiIndex::TYPE_PROJECT],
            ['payload_json' => $payload],
        );

        $this->auditRebuild($tenantId, $projectKey, 'project');

        return $payload;
    }

    /**
     * Build/refresh the per-tenant hub row (project_key='*'). Returns the payload.
     *
     * @return array<string,mixed>
     */
    public function buildTenantHub(string $tenantId): array
    {
        $projectRows = KbWikiIndex::query()->forTenant($tenantId)
            ->where('index_type', KbWikiIndex::TYPE_PROJECT)
            ->get();

        $projects = [];
        $totalPages = 0;
        $totalConcepts = 0;
        foreach ($projectRows as $row) {
            $p = is_array($row->payload_json) ? $row->payload_json : [];
            $projects[] = [
                'project_key' => (string) $row->project_key,
                'page_total' => (int) ($p['page_total'] ?? 0),
                'concept_count' => (int) ($p['concept_count'] ?? 0),
                'auto_count' => (int) ($p['auto_count'] ?? 0),
                'human_count' => (int) ($p['human_count'] ?? 0),
            ];
            $totalPages += (int) ($p['page_total'] ?? 0);
            $totalConcepts += (int) ($p['concept_count'] ?? 0);
        }
        usort($projects, static fn (array $a, array $b): int => strcmp($a['project_key'], $b['project_key']));

        $payload = [
            'project_count' => count($projects),
            'projects' => $projects,
            'total_pages' => $totalPages,
            'total_concepts' => $totalConcepts,
            'rendered_markdown' => $this->renderHubMarkdown($projects),
            'built_at' => Carbon::now()->toIso8601String(),
        ];

        KbWikiIndex::query()->forTenant($tenantId)->updateOrCreate(
            ['tenant_id' => $tenantId, 'project_key' => KbWikiIndex::HUB_PROJECT_KEY, 'index_type' => KbWikiIndex::TYPE_TENANT_HUB],
            ['payload_json' => $payload],
        );

        $this->auditRebuild($tenantId, KbWikiIndex::HUB_PROJECT_KEY, 'tenant_hub');

        return $payload;
    }

    /**
     * Read the tenant hub + every project index row.
     *
     * @return array{hub: array<string,mixed>|null, projects: list<array<string,mixed>>}
     */
    public function hub(string $tenantId): array
    {
        $rows = KbWikiIndex::query()->forTenant($tenantId)->get();
        $hub = null;
        $projects = [];
        foreach ($rows as $row) {
            $entry = [
                'project_key' => (string) $row->project_key,
                'index_type' => (string) $row->index_type,
                'payload' => is_array($row->payload_json) ? $row->payload_json : [],
                'updated_at' => optional($row->updated_at)->toIso8601String(),
            ];
            if ($row->index_type === KbWikiIndex::TYPE_TENANT_HUB) {
                $hub = $entry;
            } else {
                $projects[] = $entry;
            }
        }
        usort($projects, static fn (array $a, array $b): int => strcmp($a['project_key'], $b['project_key']));

        return ['hub' => $hub, 'projects' => $projects];
    }

    /**
     * The auto-wiki operation log: the append-only audit trail filtered to the
     * auto-wiki actor, newest first. Tenant-scoped (R30).
     *
     * @return list<array<string,mixed>>
     */
    public function operationLog(string $tenantId, ?string $projectKey = null, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        return KbCanonicalAudit::query()
            ->forTenant($tenantId)
            ->where('actor', self::AUTO_ACTOR)
            ->when($projectKey !== null && $projectKey !== '', fn ($q) => $q->where('project_key', $projectKey))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'project_key', 'doc_id', 'slug', 'event_type', 'actor', 'metadata_json', 'created_at'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'project_key' => (string) $row->project_key,
                'doc_id' => $row->doc_id,
                'slug' => $row->slug,
                'event_type' => (string) $row->event_type,
                'metadata' => is_array($row->metadata_json) ? $row->metadata_json : null,
                'created_at' => optional($row->created_at)->toIso8601String(),
            ])
            ->all();
    }

    /** @return list<string> distinct project keys for the tenant */
    private function projectKeys(string $tenantId): array
    {
        return KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->distinct()
            ->orderBy('project_key')
            ->pluck('project_key')
            ->filter(fn ($k) => is_string($k) && $k !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,int>  $countsByType
     * @param  list<array<string,string>>  $pages
     */
    private function renderProjectMarkdown(string $projectKey, array $countsByType, array $pages): string
    {
        $lines = ["# Project index — {$projectKey}", ''];
        $lines[] = '## Pages by type';
        foreach ($countsByType as $type => $count) {
            $lines[] = "- {$type}: {$count}";
        }
        $lines[] = '';
        $lines[] = '## Recently changed';
        foreach ($pages as $page) {
            $tier = $page['generation_source'] === 'auto' ? ' _(auto)_' : '';
            $lines[] = "- [[{$page['slug']}]] — {$page['title']}{$tier}";
        }

        return implode("\n", $lines)."\n";
    }

    /** @param list<array<string,mixed>> $projects */
    private function renderHubMarkdown(array $projects): string
    {
        $lines = ['# Tenant wiki hub', '', '## Projects'];
        foreach ($projects as $p) {
            $lines[] = sprintf(
                '- **%s** — %d page(s), %d concept(s) (%d auto / %d human)',
                $p['project_key'], $p['page_total'], $p['concept_count'], $p['auto_count'], $p['human_count'],
            );
        }

        return implode("\n", $lines)."\n";
    }

    private function auditRebuild(string $tenantId, string $projectKey, string $kind): void
    {
        if (! (bool) config('kb.canonical.audit_enabled', true)) {
            return;
        }
        KbCanonicalAudit::create([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'event_type' => 'graph_rebuild',
            'actor' => self::AUTO_ACTOR,
            'metadata_json' => ['source' => 'wiki_index', 'kind' => $kind],
        ]);
    }
}
