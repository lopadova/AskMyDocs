<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\AutoWiki\WikiMaintainer;
use Illuminate\Console\Command;

/**
 * v8.11/P9 — PHP surface (R44) + scheduled entry for wiki maintenance: rebuild
 * indices, lint (optionally fix), and backfill un-enriched docs. Mirrors the
 * HTTP + MCP surfaces; all three delegate to {@see WikiMaintainer}. Registered
 * as the daily `kb_wiki_maintain` Tier-1 scheduler slot.
 */
final class KbWikiMaintainCommand extends Command
{
    protected $signature = 'kb:wiki-maintain
        {--project= : project_key to maintain (default: every project in the tenant)}
        {--tenant=default : tenant to scope to}
        {--fix : also apply safe lint auto-fixes}
        {--backfill= : max un-enriched docs to backfill per project (default: config)}';

    protected $description = 'Scheduled wiki maintenance: rebuild indices + lint + backfill un-enriched docs (P9).';

    public function handle(WikiMaintainer $maintainer): int
    {
        $backfill = $this->option('backfill');
        $project = $this->option('project');

        $result = $maintainer->maintain(
            (string) $this->option('tenant'),
            ($project === null || $project === '') ? null : (string) $project,
            (bool) $this->option('fix'),
            ($backfill === null || $backfill === '') ? null : max(0, (int) $backfill),
        );

        $this->info(sprintf(
            'Maintained %d project(s): %d lint issue(s), %d doc(s) backfilled%s.',
            count($result['projects']),
            (int) $result['lint_issues'],
            (int) $result['backfilled'],
            $result['fixed'] > 0 ? ", {$result['fixed']} dangling pruned" : '',
        ));

        return self::SUCCESS;
    }
}
