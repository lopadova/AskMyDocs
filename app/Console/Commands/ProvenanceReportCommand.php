<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\ProvenanceInsightsService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.32 / ADR 0028 phase 1 — PHP read surface (R44) for ingest provenance.
 *
 * The Artisan sibling of `GET /api/admin/kb/provenance` and the MCP
 * `KbProvenanceTool`, over the SAME core {@see ProvenanceInsightsService}.
 * Tenant-scoped (R30) via `--tenant`.
 *
 * Answers the question the corpus could not answer before: how much of what
 * this tenant retrieves as grounding was written by someone outside the
 * organisation.
 */
final class ProvenanceReportCommand extends Command
{
    protected $signature = 'kb:provenance
                            {--tenant=default : Tenant to report on}
                            {--project= : Limit to one project key}
                            {--per-project : Also break the corpus down per project}
                            {--limit=50 : Max projects in the per-project breakdown}';

    protected $description = 'Report how much of the knowledge base was authored outside the organisation.';

    public function handle(ProvenanceInsightsService $service, TenantContext $tenants): int
    {
        $tenant = (string) $this->option('tenant');
        $project = $this->option('project');
        $project = is_string($project) && $project !== '' ? $project : null;

        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $summary = $service->summary($project);
            $perProject = $this->option('per-project')
                // Clamped like the HTTP and MCP surfaces: the same core,
                // asked the same way, so an operator cannot pull the whole
                // project table through the one surface that forgot to say no.
                ? $service->byProject(max(1, min(200, (int) $this->option('limit'))))
                : [];
        } finally {
            $tenants->set($previous);
        }

        $scope = $project === null ? 'all projects' : "project '{$project}'";
        $this->info("Provenance of the knowledge base (tenant: {$tenant}, {$scope})");

        if ($summary['total'] === 0) {
            // An empty corpus is a valid answer, not an error (R43/R14): a
            // fresh tenant has nothing to report and must not read as a
            // failure.
            $this->line('No documents indexed yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tier', 'Documents', '%', 'Externally authored'],
            array_map(
                static fn (array $tier): array => [
                    $tier['label'],
                    $tier['count'],
                    $tier['pct'].'%',
                    $tier['externally_authored'] ? 'yes' : 'no',
                ],
                $summary['tiers'],
            ),
        );

        $this->newLine();
        $this->line(sprintf(
            'Externally authored: %d of %d documents (%s%%).',
            $summary['externally_authored'],
            $summary['total'],
            $summary['externally_authored_pct'],
        ));

        // Reported next to the headline on purpose. A corpus that reads 100%
        // internal because nothing declared anything has not been assessed,
        // and the difference between "we checked" and "nobody told us" is the
        // whole value of the label.
        $this->line(sprintf(
            'Declared by a connector: %d — undeclared (read as %s): %d.',
            $summary['declared'],
            \Padosoft\AskMyDocsConnectorBase\ProvenanceTier::default()->label(),
            $summary['undeclared'],
        ));

        if ($summary['declared'] === 0) {
            $this->warn(
                'No document carries a connector declaration yet, so every figure above is '
                .'the default rather than an assessment.'
            );
        }

        if ($perProject !== []) {
            $this->newLine();
            $this->info('Per project (most externally authored first)');
            $this->table(
                ['Project', 'Documents', 'Externally authored', '%'],
                array_map(
                    static fn (array $row): array => [
                        $row['project_key'],
                        $row['total'],
                        $row['externally_authored'],
                        $row['externally_authored_pct'].'%',
                    ],
                    $perProject,
                ),
            );
        }

        return self::SUCCESS;
    }
}
