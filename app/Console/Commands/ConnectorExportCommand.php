<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\Connectors\ConnectorConfigExportService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.29 — PHP surface (R44) for exporting a connector account's connection
 * parameters + sync settings as a portable, SECRET-FREE JSON snapshot. The third
 * surface over the SAME core ({@see ConnectorConfigExportService}) as the HTTP
 * `GET /{installationId}/export` endpoint.
 *
 * Prints the JSON to stdout, or writes it to `--out=<file>` (R4 — the write's
 * return value is checked; a failed write fails the command, never a silent
 * "saved" that wrote nothing). Tenant-scoped (R30) via `--tenant`.
 *
 * The secret (password / tokens) is NEVER part of the output — it lives only in
 * the encrypted vault and must be re-entered on import.
 */
final class ConnectorExportCommand extends Command
{
    protected $signature = 'connectors:export
                            {installation : The connector_installations id to export}
                            {--tenant=default : Tenant the installation belongs to}
                            {--out= : Write the JSON to this file instead of stdout}';

    protected $description = 'Export a connector account\'s connection parameters + settings (secret-free).';

    public function handle(
        ConnectorConfigExportService $exporter,
        TenantContext $tenants,
    ): int {
        $id = (int) $this->argument('installation');
        $tenant = (string) $this->option('tenant');
        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $snapshot = $exporter->export($id);
        } catch (NotFoundHttpException) {
            $this->error("Installation {$id} not found for tenant '{$tenant}' (or the connector does not support export).");

            return self::FAILURE;
        } finally {
            $tenants->set($previous);
        }

        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->error('Failed to encode the export snapshot to JSON.');

            return self::FAILURE;
        }

        $out = $this->option('out');
        if ($out === null || $out === '') {
            $this->line($json);

            return self::SUCCESS;
        }

        // R4 — never ignore a side-effecting write's return value: a false from
        // file_put_contents (unwritable dir, disk full) must fail the command, not
        // report success while the file was never written.
        if (file_put_contents((string) $out, $json.PHP_EOL) === false) {
            $this->error("Could not write the export to '{$out}'.");

            return self::FAILURE;
        }

        $this->info("Exported installation {$id} to {$out}.");

        return self::SUCCESS;
    }
}
