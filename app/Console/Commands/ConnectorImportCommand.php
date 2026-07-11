<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\Connectors\ConfigureConnectorService;
use App\Services\Admin\Connectors\ConnectorConfigImportService;
use App\Services\Admin\Connectors\ConnectorImportException;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * v8.29 — PHP surface (R44) for importing a previously-exported connector-config
 * file. The CLI equivalent of the UI's "prefill a new account then Connect": it
 * parses the file through the SAME core ({@see ConnectorConfigImportService}) as
 * the HTTP `POST /{name}/import/validate` endpoint (validate envelope + strip every
 * secret / unknown key), prompts for the required secret via a HIDDEN input (never
 * a CLI arg → never in ps/shell history), then CREATES the account through the
 * normal {@see ConfigureConnectorService::configure} flow (which pings before
 * persist). Tenant-scoped (R30) — the host + package TenantContext are mirrored so
 * configure writes and the connector's ping/vault resolve in the SAME tenant
 * ({@see \App\Console\Commands\ConnectorImapInstallCommand}).
 *
 * The secret NEVER comes from the file — it is dropped on parse and re-entered
 * here — so a shared/committed config file can never carry a live credential.
 */
final class ConnectorImportCommand extends Command
{
    protected $signature = 'connectors:import
                            {file : Path to the exported connector-config JSON file}
                            {--tenant=default : Tenant to create the account in}
                            {--label= : Override the account label (default: from the file)}
                            {--project= : Override the project_key binding (default: from the file)}
                            {--actor= : Email of the user recorded as created_by (default: first user)}';

    protected $description = 'Import a connector-config file as a new account (prompts for the secret; verifies on connect).';

    public function handle(
        ConnectorConfigImportService $importer,
        ConfigureConnectorService $configurator,
        TenantContext $tenant,
        PackageTenantContext $packageTenant,
    ): int {
        $blob = $this->readBlob();
        if ($blob === null) {
            return self::FAILURE;
        }

        // Guard the nested `_meta` read: a malformed file (`_meta: "x"`) must fail
        // gracefully with the message below, never a TypeError from subscripting a
        // non-array (`?? null` does not catch it in PHP 8).
        $meta = $blob['_meta'] ?? null;
        $metaConnector = is_array($meta) ? ($meta['connector'] ?? null) : null;
        $connectorName = $this->stringOr($blob['connector'] ?? $metaConnector);
        if ($connectorName === null) {
            $this->error('The file does not record which connector it is for (missing "connector").');

            return self::FAILURE;
        }

        try {
            $prefill = $importer->parse($connectorName, $blob);
        } catch (NotFoundHttpException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ConnectorImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $payload = $this->buildPayload($prefill);

        // Prompt every required secret via a hidden input (blank is rejected — a
        // basic account cannot be created without its password).
        foreach ($prefill['secret_fields_required'] as $secretField) {
            $value = (string) $this->secret("Enter '{$secretField}' (hidden)");
            if ($value === '') {
                $this->error("A value for '{$secretField}' is required to create the account.");

                return self::FAILURE;
            }
            $payload[$secretField] = $value;
        }

        $actor = $this->resolveActor();
        if ($actor === null) {
            return self::FAILURE;
        }

        if ($prefill['dropped_keys'] !== []) {
            $this->warn('Ignored unrecognized/secret keys from the file: '.implode(', ', $prefill['dropped_keys']).'.');
        }

        return $this->create($configurator, $tenant, $packageTenant, $connectorName, $payload, $actor->id);
    }

    /**
     * @param  array{connector:string,label:?string,project_key:?string,params:array<string,mixed>,secret_fields_required:list<string>,dropped_keys:list<string>}  $prefill
     * @return array<string,mixed>
     */
    private function buildPayload(array $prefill): array
    {
        $payload = $prefill['params'];

        $label = $this->stringOr($this->option('label')) ?? $prefill['label'];
        if ($label !== null) {
            $payload['label'] = $label;
        }

        // --project overrides the file; an explicit empty --project= clears the
        // binding (inherit the tenant default).
        if ($this->option('project') !== null) {
            $payload['project_key'] = $this->stringOr($this->option('project'));
        } elseif ($prefill['project_key'] !== null) {
            $payload['project_key'] = $prefill['project_key'];
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function create(
        ConfigureConnectorService $configurator,
        TenantContext $tenant,
        PackageTenantContext $packageTenant,
        string $connectorName,
        array $payload,
        int $actorId,
    ): int {
        $tenantId = (string) $this->option('tenant');
        $previousTenant = $tenant->current();
        $previousPackageTenant = $packageTenant->current();
        $tenant->set($tenantId);
        $packageTenant->set($tenantId);

        try {
            $result = $configurator->configure($connectorName, $payload, $actorId);
        } catch (Throwable $e) {
            // R14 — credential ping failed / duplicate label / unreachable server.
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $tenant->set($previousTenant);
            $packageTenant->set($previousPackageTenant);
        }

        $installation = $result->installation;
        if ($result->redirectTo !== null) {
            // xoauth2 accounts finish via a browser round-trip — the CLI can create
            // the PENDING row but cannot complete the interactive sign-in.
            $this->warn("Account created PENDING (id {$installation->id}); finish the OAuth sign-in in the admin UI.");

            return self::SUCCESS;
        }

        $this->info("Imported '{$connectorName}' account (id {$installation->id}, label {$installation->label}, status {$installation->status}) in tenant '{$tenantId}'.");

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readBlob(): ?array
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not found or unreadable: {$path}.");

            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("Could not read file: {$path}.");

            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->error("File is not a valid JSON object: {$path}.");

            return null;
        }

        return $decoded;
    }

    private function resolveActor(): ?User
    {
        $email = $this->option('actor');

        if (is_string($email) && $email !== '') {
            $actor = User::where('email', $email)->first();
            if ($actor === null) {
                $this->error("User '{$email}' not found (--actor).");
            }

            return $actor;
        }

        $actor = User::query()->orderBy('id')->first();
        if ($actor === null) {
            $this->error('No user in the database — seed one first (db:seed).');
        }

        return $actor;
    }

    private function stringOr(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
