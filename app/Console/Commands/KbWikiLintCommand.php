<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiLinter;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.11/P5 — PHP surface (R44) of Auto-Wiki lint: report (and optionally
 * auto-fix) structural wiki-health issues for a project (or every project in
 * the tenant). Mirrors the HTTP + MCP surfaces; all three delegate to
 * {@see WikiLinter}.
 */
final class KbWikiLintCommand extends Command
{
    protected $signature = 'kb:wiki-lint
        {--project= : project_key to lint (default: every project in the tenant)}
        {--tenant=default : tenant to scope to}
        {--fix : apply safe auto-fixes (prune leftover dangling nodes)}';

    protected $description = 'Lint the Auto-Wiki (dangling/orphan/stale/missing-index) and optionally auto-fix (P5).';

    public function handle(WikiLinter $linter, TenantContext $tenants): int
    {
        $tenants->set((string) $this->option('tenant'));
        $project = $this->option('project');
        $projects = ($project === null || $project === '')
            ? $this->projectKeys($tenants->current())
            : [(string) $project];

        $totalIssues = 0;
        foreach ($projects as $projectKey) {
            $result = $linter->lint($tenants->current(), $projectKey);
            $issues = (int) array_sum($result['counts']);
            $totalIssues += $issues;
            $this->line(sprintf(
                '%s: %s (dangling %d, orphan %d, stale %d, missing-index %s)',
                $projectKey,
                $result['healthy'] ? 'healthy' : "{$issues} issue(s)",
                $result['counts']['dangling'],
                $result['counts']['orphan'],
                $result['counts']['stale_cross_ref'],
                $result['findings']['missing_index'] ? 'yes' : 'no',
            ));

            if ($this->option('fix')) {
                $fixed = $linter->fix($tenants->current(), $projectKey);
                if ($fixed['pruned_dangling'] > 0) {
                    $this->info("  fixed: pruned {$fixed['pruned_dangling']} leftover dangling node(s).");
                }
            }
        }

        $this->info(sprintf('Linted %d project(s); %d total issue(s).', count($projects), $totalIssues));

        return self::SUCCESS;
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
