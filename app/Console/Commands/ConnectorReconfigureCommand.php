<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\Connectors\ConfigureConnectorService;
use App\Services\Admin\Connectors\ConnectorConfigExportService;
use App\Services\Admin\Connectors\ConnectorConnectionTestException;
use App\Services\Admin\Connectors\ConnectorInstallationService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.29 — PHP surface (R44) for editing an existing connector account's CONNECTION
 * parameters (host/port/username/encryption) and optionally re-authenticating.
 * The third surface over the SAME core ({@see ConfigureConnectorService::reconfigure})
 * as the HTTP `POST /{installationId}/reconfigure` endpoint.
 *
 * Starts from the account's CURRENT non-secret params (so an unspecified field
 * keeps its value — PATCH semantics, mirroring the HTTP Request's prefill), applies
 * each `--set name=value` override, and — with `--set-secret` — prompts for a NEW
 * password/secret via a HIDDEN input (never a CLI arg → never in ps/shell history;
 * R: logging-security). A blank secret prompt keeps the current credential.
 *
 * The service verifies BEFORE keeping (ping / health) and rolls the config back on
 * failure, so a bad edit surfaces as a clear error + non-zero exit — never a silent
 * half-write. Tenant-scoped (R30) via `--tenant`.
 */
final class ConnectorReconfigureCommand extends Command
{
    protected $signature = 'connectors:reconfigure
                            {installation : The connector_installations id to reconfigure}
                            {--tenant=default : Tenant the installation belongs to}
                            {--set=* : Connection param override as name=value (repeatable; e.g. host=imap.new.tld)}
                            {--set-secret : Prompt for a new password/secret (hidden); blank = keep current}';

    protected $description = 'Edit an existing connector account\'s connection parameters (and optionally re-authenticate).';

    public function handle(
        ConfigureConnectorService $service,
        ConnectorConfigExportService $exporter,
        ConnectorInstallationService $installations,
        ConnectorRegistry $registry,
        TenantContext $tenants,
        PackageTenantContext $packageTenants,
    ): int {
        $id = (int) $this->argument('installation');
        $tenant = (string) $this->option('tenant');

        // Mirror BOTH tenant contexts: ConfigureConnectorService writes via the HOST
        // context, but the connector + OAuthCredentialVault read the PACKAGE context
        // (a write-once snapshot singleton). Without the mirror a --tenant other than
        // the snapshotted one makes the vault look up the installation in the wrong
        // tenant → "installation not found" after a successful ping. Restore both in
        // `finally` so the CLI run never leaks the tenant to later work in the same
        // process. Same pattern as ConnectorImportCommand / ConnectorImapInstallCommand.
        $previous = $tenants->current();
        $previousPackage = $packageTenants->current();
        $tenants->set($tenant);
        $packageTenants->set($tenant);

        try {
            return $this->process($service, $exporter, $installations, $registry, $id, $tenant);
        } catch (NotFoundHttpException) {
            $this->error("Installation {$id} not found for tenant '{$tenant}' (or the connector does not support credential configuration).");

            return self::FAILURE;
        } catch (ConnectorAuthException|ConnectorConnectionTestException $e) {
            // Verify failed — the service already rolled the config back.
            $this->error("Reconfigure rejected: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $tenants->set($previous);
            $packageTenants->set($previousPackage);
        }
    }

    private function process(
        ConfigureConnectorService $service,
        ConnectorConfigExportService $exporter,
        ConnectorInstallationService $installations,
        ConnectorRegistry $registry,
        int $id,
        string $tenant,
    ): int {
        $installation = $installations->findOr404($id);
        $connector = $registry->get($installation->connector_name);

        if (! $connector instanceof SupportsCredentialForm) {
            $this->error("Connector '{$installation->connector_name}' does not support credential configuration.");

            return self::FAILURE;
        }

        // Non-secret credential-form fields, by name (secrets excluded on purpose —
        // they can't be read and are only ever (re)set via --set-secret).
        /** @var array<string,array<string,mixed>> $byName */
        $byName = [];
        foreach ($connector->credentialFormSchema() as $field) {
            $name = (string) ($field['name'] ?? '');
            $isSecret = ($field['target'] ?? null) === 'secret' || ($field['secret'] ?? false) === true;
            if ($name !== '' && ! $isSecret) {
                $byName[$name] = $field;
            }
        }

        // Start from the CURRENT params (PATCH: an unspecified field keeps its
        // value), exactly like the HTTP Request's stored-value prefill.
        $payload = (array) ($exporter->export($id)['params'] ?? []);

        foreach ((array) $this->option('set') as $pair) {
            [$name, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $name = trim($name);

            if (! isset($byName[$name])) {
                $this->error("Unknown connection parameter '{$name}' for connector '{$installation->connector_name}'.");

                return self::FAILURE;
            }

            $payload[$name] = $this->cast($byName[$name], $value);
        }

        if ($this->option('set-secret')) {
            $secretField = $this->secretFieldName($connector);
            $secret = (string) $this->secret('New password / secret (blank = keep current)');
            if ($secret !== '') {
                $payload[$secretField] = $secret;
            }
        }

        $service->reconfigure($id, $payload);
        $this->info("Reconfigured installation {$id} (tenant '{$tenant}').");

        return self::SUCCESS;
    }

    private function secretFieldName(SupportsCredentialForm $connector): string
    {
        foreach ($connector->credentialFormSchema() as $field) {
            if (($field['target'] ?? null) === 'secret' || ($field['secret'] ?? false) === true) {
                return (string) ($field['name'] ?? 'password');
            }
        }

        return 'password';
    }

    /**
     * Cast a raw `--set` value to the field's schema type, failing fast on input
     * that can't be parsed (a typo'd `port=oops` is rejected, not coerced to 0). A
     * select value is validated against the field's options so a bad enum never
     * reaches the connector.
     *
     * @param  array<string,mixed>  $field
     *
     * @throws \InvalidArgumentException when $value can't be parsed for the type
     */
    private function cast(array $field, string $value): mixed
    {
        $name = (string) ($field['name'] ?? '');

        return match ((string) ($field['type'] ?? 'text')) {
            'number' => $this->castInt($name, $value),
            'checkbox' => $this->castBool($name, $value),
            'select' => $this->castSelect($name, (array) ($field['options'] ?? []), $value),
            default => $value,
        };
    }

    private function castInt(string $name, string $value): int
    {
        $parsed = filter_var(trim($value), FILTER_VALIDATE_INT);
        if ($parsed === false) {
            throw new \InvalidArgumentException("'{$value}' is not a valid integer for '{$name}'.");
        }

        return $parsed;
    }

    private function castBool(string $name, string $value): bool
    {
        $parsed = filter_var(trim($value), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new \InvalidArgumentException("'{$value}' is not a valid boolean (true/false/1/0/yes/no) for '{$name}'.");
        }

        return $parsed;
    }

    /**
     * @param  array<string,string>  $options
     */
    private function castSelect(string $name, array $options, string $value): string
    {
        $value = trim($value);
        if (! array_key_exists($value, $options)) {
            throw new \InvalidArgumentException(
                "'{$value}' is not a valid option for '{$name}' (allowed: ".implode(', ', array_keys($options)).').',
            );
        }

        return $value;
    }
}
