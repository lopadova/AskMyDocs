<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminCommandAudit;
use App\Services\Kb\Pii\SubjectErasureService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.23 (Ciclo 4) — PHP/CLI surface (R44) for GDPR Art.17 right-to-erasure,
 * over the SAME {@see SubjectErasureService} core as the HTTP endpoint and the
 * {@see \App\Mcp\Tools\KbEraseSubjectTool} MCP tool.
 *
 * Crypto-shreds the subject's reversible token-vault entries in a tenant.
 * Operator-level: shell access IS the authorization (mirrors the maintenance
 * commands), so it does not enforce the `pii.erase` Spatie permission — but it
 * STILL writes an `admin_command_audit` row (surface=cli). Destructive +
 * irreversible by design (that is the point of erasure).
 */
final class KbEraseSubjectCommand extends Command
{
    protected $signature = 'kb:erase-subject
                            {values* : One or more PII value(s) to crypto-shred (e.g. an email)}
                            {--tenant=default : Tenant whose vault to erase from}';

    protected $description = 'GDPR Art.17 — crypto-shred a subject\'s reversible token-vault entries in a tenant.';

    public function handle(SubjectErasureService $eraser, TenantContext $tenants): int
    {
        // Normalise (trim + de-dup) so the reported + audited count matches the
        // effective request.
        $values = $eraser->normalizeValues((array) $this->argument('values'));
        $tenant = (string) $this->option('tenant');

        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $erased = $eraser->eraseValues($tenant, $values);
            $eraser->audit(
                AdminCommandAudit::STATUS_COMPLETED,
                null,
                ['value_count' => count($values), 'erased' => $erased, 'surface' => 'cli', 'tenant' => $tenant],
            );
        } finally {
            $tenants->set($previous);
        }

        $this->info(sprintf(
            'Crypto-shredded %d vault entr%s for %d value(s) in tenant \'%s\'.',
            $erased,
            $erased === 1 ? 'y' : 'ies',
            count($values),
            $tenant,
        ));

        return self::SUCCESS;
    }
}
