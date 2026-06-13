<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\AutoWiki\EvidenceTierService;
use App\Support\Canonical\EvidenceTier;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.11/P1b — PHP surface (R44) of the evidence-tier capability: show or set
 * (human override) a document's evidence tier. Mirrors the HTTP + MCP surfaces;
 * all three delegate to {@see EvidenceTierService}.
 */
final class KbEvidenceTierCommand extends Command
{
    protected $signature = 'kb:evidence-tier
        {document : knowledge_documents id}
        {--set= : tier to set (guideline|peer_reviewed|official|preprint|news|blog|search_hint|unverified)}
        {--tenant=default : tenant to scope to}
        {--actor=cli : audit actor label}';

    protected $description = "Show or set (human override) a document's evidence tier (AutoSci #67).";

    public function handle(EvidenceTierService $service, TenantContext $tenants): int
    {
        $tenants->set((string) $this->option('tenant'));

        $doc = $service->findForTenant((int) $this->argument('document'));
        if ($doc === null) {
            $this->error('Document not found in tenant '.$this->option('tenant').'.');

            return self::FAILURE;
        }

        $set = $this->option('set');
        if ($set === null || $set === '') {
            $this->line('evidence_tier: '.($doc->evidence_tier ?? '(not assessed)'));

            return self::SUCCESS;
        }

        $tier = EvidenceTier::tryFromLoose($set);
        if ($tier === null) {
            $this->error('Invalid tier "'.$set.'". Valid: '.implode(', ', EvidenceTier::values()).'.');

            return self::FAILURE;
        }

        $service->setTier($doc, $tier, (string) $this->option('actor'));
        $this->info("Set evidence_tier={$tier->value} on document {$doc->id}.");

        return self::SUCCESS;
    }
}
