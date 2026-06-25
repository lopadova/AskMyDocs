<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KbPiiSetting;
use App\Services\Kb\Pii\KbPiiPolicyResolver;
use Illuminate\Console\Command;

/**
 * v8.23 (Ciclo 4) — PHP/CLI read surface (R44) for the PII ingestion policy.
 *
 * The Artisan sibling of the HTTP `GET /api/admin/pii/policy` index and the MCP
 * {@see \App\Mcp\Tools\KbPiiPolicyTool}, over the SAME core
 * {@see KbPiiPolicyResolver}. Prints the effective `redact_enabled` + `strategy`
 * the inline ingestion path would apply for a (tenant, project), tenant-scoped
 * (R30). Read-only.
 */
final class KbPiiPolicyCommand extends Command
{
    protected $signature = 'kb:pii-policy
                            {--tenant=default : Tenant to report on}
                            {--project= : Project scope to resolve (defaults to the tenant-wide "*")}';

    protected $description = 'Show the effective PII ingestion policy (redact on/off + strategy) for a tenant/project.';

    public function handle(KbPiiPolicyResolver $resolver): int
    {
        $tenant = (string) $this->option('tenant');
        $rawProject = $this->option('project');
        $project = is_string($rawProject) && trim($rawProject) !== '' ? trim($rawProject) : KbPiiSetting::WILDCARD;

        $effective = $resolver->resolve($tenant, $project);

        $this->info("PII ingestion policy (tenant: {$tenant}, project: {$project})");
        $this->table(
            ['Setting', 'Effective'],
            [
                ['redact_enabled', $effective['redact_enabled'] ? 'true' : 'false'],
                ['strategy', $effective['strategy']],
            ],
        );

        return self::SUCCESS;
    }
}
