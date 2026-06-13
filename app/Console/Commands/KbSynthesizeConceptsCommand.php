<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\AutoWiki\ConceptSynthesizer;
use Illuminate\Console\Command;

/**
 * v8.11/P3 — PHP surface (R44) of concept-page synthesis: sweep a project and
 * synthesize auto-tier `domain-concept` pages for recurring concepts that lack
 * a page. Mirrors the HTTP + MCP surfaces; all three delegate to
 * {@see ConceptSynthesizer}.
 */
final class KbSynthesizeConceptsCommand extends Command
{
    protected $signature = 'kb:synthesize-concepts
        {project : project_key to sweep}
        {--tenant=default : tenant to scope to}
        {--limit= : max concept pages to create this run (default: config cap)}';

    protected $description = 'Synthesize auto-tier domain-concept pages for recurring concepts in a project (P3).';

    public function handle(ConceptSynthesizer $synthesizer): int
    {
        $limitOpt = $this->option('limit');
        $limit = ($limitOpt === null || $limitOpt === '') ? null : max(0, (int) $limitOpt);

        $result = $synthesizer->synthesize(
            (string) $this->option('tenant'),
            (string) $this->argument('project'),
            $limit,
        );

        if (($result['ran'] ?? false) !== true) {
            $this->warn('Did not run: '.($result['reason'] ?? 'unknown').'.');

            return self::SUCCESS;
        }

        $created = $result['created'] ?? [];
        $this->info(sprintf(
            '%d candidate concept(s); created %d page(s)%s; skipped %d.',
            (int) ($result['candidates'] ?? 0),
            count($created),
            $created === [] ? '' : ' ['.implode(', ', $created).']',
            count($result['skipped'] ?? []),
        ));

        return self::SUCCESS;
    }
}
