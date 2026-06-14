<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\AutoWiki\WikiIndexBuilder;
use Illuminate\Console\Command;

/**
 * v8.11/P4 — PHP surface (R44) of Auto-Wiki indices: rebuild the per-project
 * roll-up(s) + the per-tenant hub. Mirrors the HTTP + MCP surfaces; all three
 * delegate to {@see WikiIndexBuilder}.
 */
final class KbWikiIndexCommand extends Command
{
    protected $signature = 'kb:wiki-index
        {--project= : project_key to rebuild (default: every project in the tenant)}
        {--tenant=default : tenant to scope to}';

    protected $description = 'Rebuild the Auto-Wiki indices (per-project roll-ups + per-tenant hub) (P4).';

    public function handle(WikiIndexBuilder $builder): int
    {
        $project = $this->option('project');
        $result = $builder->rebuild(
            (string) $this->option('tenant'),
            ($project === null || $project === '') ? null : (string) $project,
        );

        $this->info(sprintf(
            'Rebuilt %d project index/indices; hub covers %d project(s).',
            count($result['projects']),
            (int) $result['hub_project_count'],
        ));

        return self::SUCCESS;
    }
}
