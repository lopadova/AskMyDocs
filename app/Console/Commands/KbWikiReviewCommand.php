<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiReviewer;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.11/P7 — PHP surface (R44) of cross-model review: have an independent
 * review-LLM audit an auto-tier document (grounding / cross-refs / novelty /
 * contradictions). Mirrors the HTTP + MCP surfaces; all three delegate to
 * {@see AutoWikiReviewer}.
 */
final class KbWikiReviewCommand extends Command
{
    protected $signature = 'kb:wiki-review
        {document : knowledge_documents id}
        {--tenant=default : tenant to scope to}';

    protected $description = 'Cross-model review of an auto-tier page (grounding/novelty/contradictions) (P7).';

    public function handle(AutoWikiReviewer $reviewer, TenantContext $tenants): int
    {
        $tenants->set((string) $this->option('tenant'));

        $doc = KnowledgeDocument::query()
            ->forTenant($tenants->current())
            ->find((int) $this->argument('document'));
        if ($doc === null) {
            $this->error('Document not found in tenant '.$this->option('tenant').'.');

            return self::FAILURE;
        }

        $result = $reviewer->review($doc);
        if (($result['reviewed'] ?? false) !== true) {
            $this->warn('Not reviewed: '.($result['reason'] ?? 'unknown').'.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Verdict: %s (grounded=%s, cross_refs_valid=%s, novelty=%s, contradictions=%d).',
            $result['verdict'],
            $result['grounded'] ? 'yes' : 'no',
            $result['cross_refs_valid'] ? 'yes' : 'no',
            $result['novelty'],
            count($result['contradictions'] ?? []),
        ));

        return self::SUCCESS;
    }
}
