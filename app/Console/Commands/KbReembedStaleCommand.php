<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\Embedding\ReembedStaleService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.42/W2 (ADR 0035 §D2) — re-embed the chunks whose vector was produced
 * under a different recipe (provider / model / input template / chunking) than
 * the active one, and ONLY those documents. Thin adapter over
 * {@see ReembedStaleService} (R44: the read surface of the same core is the
 * additive `stale_embeddings` key on the ingestion-status CLI / HTTP / MCP).
 *
 *   php artisan kb:reembed-stale --dry-run           # counts + token/cost estimate, queues nothing
 *   php artisan kb:reembed-stale --project=ops       # queue one ReembedDocumentJob per stale document
 *
 * Idempotent and resumable: a document that was re-embedded is no longer stale,
 * so re-running only picks up what is left.
 */
final class KbReembedStaleCommand extends Command
{
    protected $signature = 'kb:reembed-stale
        {--project= : Restrict to one project_key (default: every project of the tenant)}
        {--tenant=default : Tenant that owns the documents}
        {--batch=100 : Documents streamed per page (1-500)}
        {--dry-run : Report what is stale and the estimated cost; queue nothing}';

    protected $description = 'Re-embed documents whose chunk vectors were produced under a different embedding recipe.';

    public function handle(ReembedStaleService $reembedStaleService, TenantContext $tenantContext): int
    {
        $tenant = trim((string) $this->option('tenant'));
        $project = trim((string) $this->option('project'));

        if ($tenant === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }

        $previous = $tenantContext->current();
        $tenantContext->set($tenant);

        try {
            $result = $reembedStaleService->run(
                $tenant,
                $project === '' ? null : $project,
                (int) $this->option('batch'),
                (bool) $this->option('dry-run'),
            );
        } finally {
            $tenantContext->set($previous);
        }

        $s = $result['summary'];
        $this->line(sprintf(
            'Active fingerprint %s (template %s) — %d stale chunk(s) in %d document(s) of %d total chunk(s).',
            $s['active_fingerprint'],
            $s['template'],
            $s['stale_chunks'],
            $s['stale_documents'],
            $s['total_chunks'],
        ));
        $this->line(sprintf(
            'Estimated re-embed volume: ~%d token(s)%s.',
            $s['estimated_tokens'],
            $s['estimated_cost'] === null ? ' (set KB_EMBEDDING_PRICE_PER_MILLION_TOKENS for a cost estimate)' : sprintf(' ≈ %s USD', number_format($s['estimated_cost'], 4)),
        ));

        if ($result['dry_run']) {
            $this->info('Dry run — nothing queued.');

            return self::SUCCESS;
        }

        $this->info(sprintf("Queued %d document(s) for re-embed (tenant '%s').", $result['queued'], $tenant));

        return self::SUCCESS;
    }
}
