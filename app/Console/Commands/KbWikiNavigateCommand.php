<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\AutoWiki\WikiNavigator;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.11/P6 — PHP surface (R44) of agentic graph-navigation: multi-hop BFS over
 * the wiki graph from explicit seeds, or anchor-driven from the project index.
 * Mirrors the HTTP + MCP surfaces; all three delegate to {@see WikiNavigator}.
 */
final class KbWikiNavigateCommand extends Command
{
    protected $signature = 'kb:wiki-navigate
        {project : project_key to navigate}
        {--seeds= : comma-separated seed slugs (default: anchor-driven from the index)}
        {--depth= : BFS depth (1-5, default: config)}
        {--tenant=default : tenant to scope to}';

    protected $description = 'Multi-hop navigate the Auto-Wiki graph from seeds or index anchors (P6).';

    public function handle(WikiNavigator $navigator, TenantContext $tenants): int
    {
        $tenants->set((string) $this->option('tenant'));
        $project = (string) $this->argument('project');
        $depthOpt = $this->option('depth');
        $depth = ($depthOpt === null || $depthOpt === '') ? null : (int) $depthOpt;

        $seedsOpt = (string) ($this->option('seeds') ?? '');
        $seeds = array_values(array_filter(array_map('trim', explode(',', $seedsOpt)), static fn ($s) => $s !== ''));

        $result = $seeds === []
            ? $navigator->navigateFromAnchors($tenants->current(), $project, $depth)
            : $navigator->navigate($tenants->current(), $project, $seeds, $depth);

        $this->info(sprintf(
            'Navigated %s from %d seed(s) depth %d → reached %d node(s)%s.',
            $project,
            count($result['seeds']),
            (int) $result['depth'],
            count($result['reached']),
            ($result['truncated'] ?? false) ? ' (truncated)' : '',
        ));
        foreach (array_slice($result['reached'], 0, 10) as $node) {
            $this->line(sprintf('  [hop %d] %s ← %s (%s)', $node['hop'], $node['slug'], $node['from'], $node['via_edge_type']));
        }

        return self::SUCCESS;
    }
}
