<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\Pii\ReembedProjectService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.23 (Ciclo 4, PR5) — PHP/CLI surface (R44) to re-embed a project after a
 * PII-policy change, over the SAME {@see ReembedProjectService} core as the HTTP
 * endpoint and the {@see \App\Mcp\Tools\KbReembedProjectTool} MCP tool.
 *
 * Queues one re-embed job per live document so chunks + embeddings are
 * re-derived from disk under the current policy. Operator-level (shell access).
 */
final class KbReembedProjectCommand extends Command
{
    protected $signature = 'kb:reembed-project
                            {project : The project_key to re-embed}
                            {--tenant=default : Tenant that owns the project}';

    protected $description = 'Queue a re-embed of a project\'s documents under the current PII policy.';

    public function handle(ReembedProjectService $service, TenantContext $tenants): int
    {
        $project = trim((string) $this->argument('project'));
        $tenant = trim((string) $this->option('tenant'));

        // Fail fast on a blank project (would queue 0 silently) or a blank
        // tenant (would bind an invalid context) — tri-surface parity.
        if ($project === '') {
            $this->error('A non-empty project is required.');

            return self::FAILURE;
        }
        if ($tenant === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }

        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $queued = $service->reembedProject($tenant, $project);
        } finally {
            $tenants->set($previous);
        }

        $this->info(sprintf('Queued %d document(s) for re-embed in project \'%s\' (tenant \'%s\').', $queued, $project, $tenant));

        return self::SUCCESS;
    }
}
