<?php

declare(strict_types=1);

namespace App\Services\Kb\AutoWiki;

use App\Jobs\AutoWikiCompilerJob;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;

/**
 * v8.11/P9 — scheduled wiki maintenance (Karpathy lint cadence / AutoSci
 * scheduled discovery): "knowledge improves over time".
 *
 * A periodic sweep that orchestrates the earlier phases over a (tenant, project):
 *   - rebuild the indices (P4 {@see WikiIndexBuilder}) — fresh map + hub;
 *   - lint wiki health (P5 {@see WikiLinter}) — report (and optionally fix);
 *   - backfill: dispatch {@see AutoWikiCompilerJob} for un-enriched docs (no
 *     `_autowiki` block yet) so the corpus converges toward full enrichment,
 *     bounded per run.
 *
 * Pure orchestration — no new mutation logic of its own; every effect goes
 * through the already-reviewed P4/P5/P1 services (so their firewalls/audits
 * apply). Tenant-scoped (R30). Runs from the Tier-1 scheduler (daily) and the
 * tri-surface trigger (command/API/MCP).
 */
class WikiMaintainer
{
    public function __construct(
        private readonly WikiIndexBuilder $indexBuilder,
        private readonly WikiLinter $linter,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Maintain one project (when given) or every project in the tenant.
     *
     * @return array{projects: list<string>, lint_issues: int, backfilled: int, fixed: int}
     */
    public function maintain(string $tenantId, ?string $projectKey = null, bool $fix = false, ?int $backfillLimit = null): array
    {
        $this->tenants->set($tenantId);

        $projects = $projectKey !== null && $projectKey !== ''
            ? [$projectKey]
            : $this->projectKeys($tenantId);

        $backfillLimit ??= (int) config('kb.autowiki.maintenance_backfill_limit', 25);

        $lintIssues = 0;
        $backfilled = 0;
        $fixed = 0;

        foreach ($projects as $project) {
            $this->indexBuilder->buildProjectIndex($tenantId, $project);

            $lint = $this->linter->lint($tenantId, $project);
            $lintIssues += (int) array_sum($lint['counts']);
            if ($fix) {
                $fixed += (int) ($this->linter->fix($tenantId, $project)['pruned_dangling'] ?? 0);
            }

            $backfilled += $this->backfillUnenriched($tenantId, $project, $backfillLimit);
        }

        $this->indexBuilder->buildTenantHub($tenantId);

        return ['projects' => $projects, 'lint_issues' => $lintIssues, 'backfilled' => $backfilled, 'fixed' => $fixed];
    }

    /**
     * Dispatch the compiler for up to $limit docs in the project that have no
     * `_autowiki` block yet (never enriched). Returns how many were dispatched.
     */
    private function backfillUnenriched(string $tenantId, string $projectKey, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $ids = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->whereNull('frontmatter_json->_autowiki')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            AutoWikiCompilerJob::dispatch((int) $id, $tenantId);
        }

        return $ids->count();
    }

    /** @return list<string> */
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
}
