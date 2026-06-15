<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiExplorerService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.11/P10 — PHP surface (R44) of the Wiki Explorer writes: promote an auto
 * page to the human-vouched tier, or (with --discard) soft-delete it. Mirrors
 * the HTTP + MCP surfaces; all three delegate to {@see WikiExplorerService}.
 */
final class KbWikiPromoteCommand extends Command
{
    protected $signature = 'kb:wiki-promote
        {document : knowledge_documents id}
        {--discard : soft-delete the auto page instead of promoting it}
        {--tenant=default : tenant to scope to}';

    protected $description = 'Promote an auto-tier wiki page to human (or --discard it) (P10).';

    public function handle(WikiExplorerService $explorer, TenantContext $tenants): int
    {
        $tenants->set((string) $this->option('tenant'));

        $doc = KnowledgeDocument::query()
            ->forTenant($tenants->current())
            ->find((int) $this->argument('document'));
        if ($doc === null) {
            $this->error('Document not found in tenant '.$this->option('tenant').'.');

            return self::FAILURE;
        }

        $actor = 'cli:kb:wiki-promote';

        if ((bool) $this->option('discard')) {
            $result = $explorer->discard($doc, $actor);
            if (($result['discarded'] ?? false) !== true) {
                $this->warn('Not discarded: '.($result['reason'] ?? 'unknown').'.');

                return self::SUCCESS;
            }
            $this->info('Discarded auto page '.($result['slug'] ?? $doc->id).'.');

            return self::SUCCESS;
        }

        $result = $explorer->promote($doc, $actor);
        if (($result['promoted'] ?? false) !== true) {
            $this->warn('Not promoted: '.($result['reason'] ?? 'unknown').'.');

            return self::SUCCESS;
        }

        $this->info('Promoted '.($result['slug'] ?? $doc->id).' to the human-vouched tier.');

        return self::SUCCESS;
    }
}
