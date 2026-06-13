<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiGraphLinker;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.11/P2 — PHP surface (R44) of auto-wiki graph canonicalization: (re)build
 * the navigable graph (nodes + inferred edges) for a single auto-tier document
 * from its compiled cross-references. Mirrors the HTTP + MCP surfaces; all three
 * delegate to {@see AutoWikiGraphLinker}.
 */
final class KbWikiLinkCommand extends Command
{
    protected $signature = 'kb:wiki-link
        {document : knowledge_documents id}
        {--tenant=default : tenant to scope to}';

    protected $description = 'Rebuild the auto-wiki graph (nodes + inferred edges) for a document (P2, AutoSci edges).';

    public function handle(AutoWikiGraphLinker $linker, TenantContext $tenants): int
    {
        $tenants->set((string) $this->option('tenant'));

        $doc = KnowledgeDocument::query()
            ->forTenant($tenants->current())
            ->find((int) $this->argument('document'));
        if ($doc === null) {
            $this->error('Document not found in tenant '.$this->option('tenant').'.');

            return self::FAILURE;
        }

        $result = $linker->link($doc);
        if (($result['linked'] ?? false) !== true) {
            $this->warn('Not linked: '.($result['reason'] ?? 'unknown').'.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Linked %s: %d node(s), %d edge(s)%s.',
            $result['slug'] ?? '(no slug)',
            (int) ($result['nodes_created'] ?? 0),
            (int) ($result['edges_created'] ?? 0),
            ($result['slug_assigned'] ?? false) ? ' (slug auto-assigned)' : '',
        ));

        return self::SUCCESS;
    }
}
